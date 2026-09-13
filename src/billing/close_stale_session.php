<?php
/**
 * Stale Session Sweep (Day 12 — T-12, REVISED)
 * -----------------------------------------------------------------
 * Run via Hostinger hPanel Cron Job, NOT through public/index.php —
 * invoked directly by PHP CLI/cron, no $_SESSION context. Bootstraps
 * config/db.php directly.
 *
 * SETUP (hPanel > Websites > [domain] > Cron Jobs):
 *   Type: PHP
 *   Path: /home/<user>/domains/dynakrit.store/public_html/src/billing/close_stale_session.php
 *   Schedule: every 1 minute
 *   ⚠️ MENTOR REVIEW: confirm the exact absolute path once deployed.
 *
 * *** ADDED — patient-confirmation handshake, two-pass sweep ***
 * PASS 1 (unchanged logic, one added filter): finds every 'active'
 * chat_sessions row that HAS been confirmed (confirmed_at IS NOT NULL)
 * whose last known checkpoint is older than BILLING_GRACE_SECONDS, and
 * finalizes billing for each via finalizeBilling() exactly as before.
 *
 * PASS 2 (new): finds every 'active' chat_sessions row that has NEVER
 * been confirmed (confirmed_at IS NULL) — i.e. the doctor accepted but
 * the patient never entered the chat room — and closes it with ZERO
 * billing once CONFIRMATION_TIMEOUT_SECONDS has passed. Deliberately
 * does NOT call finalizeBilling(): that function computes real elapsed
 * time from started_at, and an unconfirmed session's started_at is
 * accept-time, not a real usage window. Billing math doesn't apply
 * here at all — this is a plain state-closure, not a billing event.
 *
 * Without this split, an abandoned unconfirmed session would fall
 * through to Pass 1's old query (COALESCE(last_heartbeat_at,
 * started_at) — both still point at accept-time) and get billed for
 * the entire accept-to-timeout gap as if it were real chat time. This
 * was a live bug caught during Day 14 integration testing.
 *
 * BUGFIX (notifications): Pass 1's SELECT builds its own $session array
 * independently of getAuthorizedSession() — it never calls that
 * function at all. So when session_helpers.php was patched to add
 * doctor_user_id/patient_user_id for notification recipient resolution,
 * that fix never reached this file's own separate query, leaving this
 * path (auto_timeout) silently unable to notify anyone even though
 * finalizeBilling() itself was ready to. Added the same two columns
 * here via the same joins, keeping this query in sync with
 * getAuthorizedSession()'s shape.
 *
 * *** ADDED — Pass 2 confirmation-timeout notifications ***
 * After a successful zero-cost close, notify doctor (and patient) with
 * type SESSION_CONFIRMATION_EXPIRED. Deliberately NOT the same as
 * PATIENT_SESSION_DECLINED (explicit reject) or CONSULTATION_* (real
 * consultation started). Notifications fire AFTER commit so a notif
 * failure cannot roll back the closure.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/billing_service.php';
require_once __DIR__ . '/../notifications/notification_service.php';

// No requireAuth() here on purpose — see file header.

// ============================================================
// PASS 1 — genuinely abandoned mid-chat sessions (billed in full)
// ============================================================

$graceThreshold = (new DateTime())->modify('-' . BILLING_GRACE_SECONDS . ' seconds');

$stmt = $pdo->prepare(
    "SELECT
        cs.id,
        cs.request_id,
        cr.patient_id,
        cr.doctor_id,
        cs.status,
        cs.started_at,
        cs.last_heartbeat_at,
        cs.rate_per_minute,
        dp.user_id AS doctor_user_id,
        pp.user_id AS patient_user_id
     FROM chat_sessions cs
     INNER JOIN chat_requests cr ON cr.id = cs.request_id
     INNER JOIN doctor_profiles dp ON dp.id = cr.doctor_id
     INNER JOIN patient_profiles pp ON pp.id = cr.patient_id
     WHERE cs.status = 'active'
       AND cs.confirmed_at IS NOT NULL
       AND COALESCE(cs.last_heartbeat_at, cs.started_at) < ?"
);
$stmt->execute([$graceThreshold->format('Y-m-d H:i:s')]);
$staleSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$closedCount = 0;

foreach ($staleSessions as $session) {
    $checkpoint = $session['last_heartbeat_at']
        ? DateTime::createFromFormat('Y-m-d H:i:s', $session['last_heartbeat_at'])
        : DateTime::createFromFormat('Y-m-d H:i:s', $session['started_at']);

    // Bill in full up to checkpoint + grace, not "now".
    $endedAt = (clone $checkpoint)->modify('+' . BILLING_GRACE_SECONDS . ' seconds');

    try {
        $result = finalizeBilling($pdo, $session, $endedAt, 'auto_timeout');
        if (!$result['already_finalized']) {
            $closedCount++;
        }
    } catch (Exception $e) {
        fwrite(STDERR, "Failed to close session {$session['id']}: " . $e->getMessage() . "\n");
        continue;
    }
}

// ============================================================
// PASS 2 — accepted but never confirmed (closed, zero billing)
// ============================================================

$confirmationThreshold = (new DateTime())->modify('-' . CONFIRMATION_TIMEOUT_SECONDS . ' seconds');

// Include recipient user ids so we can notify after a successful close.
$stmt = $pdo->prepare(
    "SELECT
        cs.id,
        dp.user_id AS doctor_user_id,
        pp.user_id AS patient_user_id
     FROM chat_sessions cs
     INNER JOIN chat_requests cr ON cr.id = cs.request_id
     INNER JOIN doctor_profiles dp ON dp.id = cr.doctor_id
     INNER JOIN patient_profiles pp ON pp.id = cr.patient_id
     WHERE cs.status = 'active'
       AND cs.confirmed_at IS NULL
       AND cs.started_at < ?"
);
$stmt->execute([$confirmationThreshold->format('Y-m-d H:i:s')]);
$unconfirmedSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$unconfirmedClosedCount = 0;

foreach ($unconfirmedSessions as $row) {
    $sessionId = (int) $row['id'];
    $doctorUserId = (int) $row['doctor_user_id'];
    $patientUserId = (int) $row['patient_user_id'];

    $pdo->beginTransaction();

    try {
        // Idempotency guard, same shape as finalizeBilling()'s own —
        // WHERE clause on the current state, rowCount() to confirm if
        // race was actually won against another sweep run/instance.
        $sessionUpdate = $pdo->prepare(
            "UPDATE chat_sessions
             SET status = 'ended', ended_at = NOW()
             WHERE id = ? AND status = 'active' AND confirmed_at IS NULL"
        );
        $sessionUpdate->execute([$sessionId]);

        if ($sessionUpdate->rowCount() === 0) {
            // Already closed by a concurrent run, or got confirmed in
            // the gap between the SELECT above and now — either way,
            // nothing to do.
            $pdo->rollBack();
            continue;
        }

        // Resolve the pending billing_records row respond.php already
        // created at accept time — zero amounts, distinct end_reason
        // so this is never confused with a real auto_timeout in reports.
        // Deliberately no doctor_earnings insert: $0 owed for a no-show
        // isn't a meaningful ledger entry. (Flagged for mentor — reverse
        // this call if a $0 row is wanted for completeness/auditing.)
        $billingUpdate = $pdo->prepare(
            "UPDATE billing_records
             SET duration_seconds = 0, gross_amount = 0, commission_amount = 0, doctor_amount = 0,
                 billing_status = 'finalized', end_reason = 'unconfirmed_expired'
             WHERE session_id = ? AND billing_status = 'pending'"
        );
        $billingUpdate->execute([$sessionId]);

        $pdo->commit();
        $unconfirmedClosedCount++;

        // Notifications AFTER commit — never block or roll back closure.
        // Distinct from PATIENT_SESSION_DECLINED (explicit reject) and
        // CONSULTATION_* (consultation actually started).
        try {
            if ($doctorUserId > 0) {
                createNotification(
                    $pdo,
                    $doctorUserId,
                    'doctor',
                    'SESSION_CONFIRMATION_EXPIRED',
                    'Consultation expired',
                    'The patient did not join in time. This consultation was closed with no charge.',
                    'chat_session',
                    $sessionId
                );
            }
            if ($patientUserId > 0) {
                createNotification(
                    $pdo,
                    $patientUserId,
                    'patient',
                    'SESSION_CONFIRMATION_EXPIRED',
                    'Consultation expired',
                    'You did not join this consultation in time. It was closed with no charge.',
                    'chat_session',
                    $sessionId
                );
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "Failed to notify for unconfirmed session {$sessionId}: " . $e->getMessage() . "\n");
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Failed to close unconfirmed session {$sessionId}: " . $e->getMessage() . "\n");
        continue;
    }
}

echo "Stale session sweep complete. "
    . "Billed closures: {$closedCount} of " . count($staleSessions) . " candidates. "
    . "Unconfirmed closures: {$unconfirmedClosedCount} of " . count($unconfirmedSessions) . " candidates.\n";
