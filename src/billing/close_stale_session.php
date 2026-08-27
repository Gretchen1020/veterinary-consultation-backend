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
 *   Path: /home/<user>/domains/dynakrit.store/public_html/src/billing/close_stale_sessions.php
 *   Schedule: every 1 minute
 *   ⚠️ MENTOR REVIEW: confirm the exact absolute path once deployed.
 *
 * REVISION NOTE: no longer writes to session_billing_log (removed —
 * superseded by billing_records.end_reason = 'auto_timeout'). Evidence
 * for T-12 is now: SELECT * FROM billing_records WHERE end_reason = 'auto_timeout'.
 *
 * Finds every 'active' chat_sessions row whose last known checkpoint
 * (last_heartbeat_at, or started_at if no heartbeat ever arrived) is
 * older than BILLING_GRACE_SECONDS, and finalizes billing for each —
 * billing IN FULL up to (checkpoint + grace), arrears never written off
 * (Mandatory Rule #6).
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/billing_service.php';

// No requireAuth() here on purpose — see file header.

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
        cs.rate_per_minute
     FROM chat_sessions cs
     INNER JOIN chat_requests cr ON cr.id = cs.request_id
     WHERE cs.status = 'active'
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

echo "Stale session sweep complete. Closed: {$closedCount} of " . count($staleSessions) . " candidates.\n";