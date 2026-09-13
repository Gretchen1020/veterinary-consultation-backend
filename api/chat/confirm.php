<?php
/**
 * POST /api/chat/confirm
 *
 * Patient accept / reject of an accepted session (post-doctor-accept handshake).
 *
 * Body:
 *   { "session_id": <int>, "action": "accept" | "reject" }
 *
 * - action omitted or "accept" → confirm handshake (starts billing clock) + notify doctor
 * - action "reject"           → patient declines before join; zero-cost close + notify doctor
 *
 * Only the owning patient may act. Session must still be active and not yet confirmed
 * (reject after confirm is not supported — use session end for that).
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/patient_profile.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/notifications/notification_service.php';

requireAuth('patient');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed');
}

$patientId = getPatientProfileId($pdo, $_SESSION['user_id']);

if ($patientId === null) {
    sendError(404, 'Patient profile not found');
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$missing = checkRequiredFields($input, ['session_id']);
if ($missing) {
    sendError(400, 'Missing required fields: ' . implode(', ', $missing));
}

$sessionId = (int) $input['session_id'];
if ($sessionId <= 0) {
    sendError(400, 'Invalid session_id');
}

// Backward compatible: missing action = accept (legacy clients only sent session_id)
$action = $input['action'] ?? 'accept';
if (!isValidEnum($action, ['accept', 'reject'])) {
    sendError(400, 'action must be accept or reject');
}

$doctorUserId = null;
$resultPayload = null;

$pdo->beginTransaction();

try {
    // Lock the session row so accept/reject cannot race each other or the sweep.
    $stmt = $pdo->prepare(
        "SELECT
            cs.id,
            cs.request_id,
            cs.status,
            cs.started_at,
            cs.confirmed_at,
            cr.patient_id,
            cr.doctor_id
         FROM chat_sessions cs
         INNER JOIN chat_requests cr ON cr.id = cs.request_id
         WHERE cs.id = ?
         FOR UPDATE"
    );
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        $pdo->rollBack();
        sendError(404, 'Session not found');
    }

    if ((int) $session['patient_id'] !== $patientId) {
        $pdo->rollBack();
        sendError(403, 'This session does not belong to you');
    }

    if ($session['status'] !== 'active') {
        $pdo->rollBack();
        sendError(409, 'Session has already ended');
    }

    if ($session['confirmed_at'] !== null) {
        $pdo->rollBack();
        sendError(409, 'Session has already been confirmed');
    }

    // Doctor's users.id for notification (separate unlocked lookup — same pattern as respond.php)
    $doctorLookup = $pdo->prepare(
        "SELECT user_id FROM doctor_profiles WHERE id = ?"
    );
    $doctorLookup->execute([(int) $session['doctor_id']]);
    $doctorUserId = (int) $doctorLookup->fetchColumn();

    if ($action === 'reject') {
        // Patient declines after doctor accepted, before billing starts.
        // Mirror close_stale unconfirmed path: end session, zero-out pending billing, no earnings.

        $stmt = $pdo->prepare(
            "UPDATE chat_sessions
             SET status = 'ended',
                 ended_at = NOW()
             WHERE id = ?
               AND status = 'active'
               AND confirmed_at IS NULL"
        );
        $stmt->execute([$sessionId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            sendError(409, 'Session could not be declined (already confirmed or ended)');
        }

        // Zero-cost finalize of the pending billing row created at accept (if any).
        // end_reason: patient_declined — run the ALTER below if your enum does not include it yet.
        // Fallback-safe alternative used by sweep: 'unconfirmed_expired'
        $billingUpdate = $pdo->prepare(
            "UPDATE billing_records
             SET duration_seconds = 0,
                 gross_amount = 0,
                 commission_amount = 0,
                 doctor_amount = 0,
                 billing_status = 'finalized',
                 end_reason = 'patient_declined'
             WHERE session_id = ?
               AND billing_status = 'pending'"
        );
        try {
            $billingUpdate->execute([$sessionId]);
        } catch (PDOException $e) {
            // Enum may not include patient_declined yet — fall back to unconfirmed_expired
            $billingUpdate = $pdo->prepare(
                "UPDATE billing_records
                 SET duration_seconds = 0,
                     gross_amount = 0,
                     commission_amount = 0,
                     doctor_amount = 0,
                     billing_status = 'finalized',
                     end_reason = 'unconfirmed_expired'
                 WHERE session_id = ?
                   AND billing_status = 'pending'"
            );
            $billingUpdate->execute([$sessionId]);
        }

        $pdo->commit();

        $resultPayload = [
            'session_id' => $sessionId,
            'status'     => 'ended',
            'action'     => 'reject',
            'confirmed'  => false,
            'reason'     => 'patient_declined',
        ];
    } else {
        // action === 'accept' — billing clock starts here
        $stmt = $pdo->prepare(
            "UPDATE chat_sessions
             SET
                confirmed_at = NOW(),
                started_at = NOW(),
                last_heartbeat_at = NULL
             WHERE id = ?
               AND confirmed_at IS NULL
               AND status = 'active'"
        );
        $stmt->execute([$sessionId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            sendError(409, 'Session could not be confirmed (already confirmed or ended)');
        }

        $pdo->commit();

        $stmt = $pdo->prepare(
            "SELECT confirmed_at, started_at FROM chat_sessions WHERE id = ?"
        );
        $stmt->execute([$sessionId]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        $resultPayload = [
            'session_id'   => $sessionId,
            'status'       => 'active',
            'action'       => 'accept',
            'confirmed'    => true,
            'confirmed_at' => $updated['confirmed_at'],
            'started_at'   => $updated['started_at'],
        ];
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('confirm.php failed: ' . $e->getMessage());
    sendError(500, 'Failed to process session confirmation');
}

// Notifications AFTER commit — never rollBack on notif failure
if ($doctorUserId > 0) {
    try {
        if ($action === 'reject') {
            createNotification(
                $pdo,
                $doctorUserId,
                'doctor',
                'PATIENT_SESSION_DECLINED',
                'Patient declined the consultation',
                'The patient declined to join this consultation. No charges were applied.',
                'chat_session',
                $sessionId
            );
        } else {
            createNotification(
                $pdo,
                $doctorUserId,
                'doctor',
                'PATIENT_SESSION_CONFIRMED',
                'Patient joined the consultation',
                'The patient confirmed and joined the consultation. Billing has started.',
                'chat_session',
                $sessionId
            );
        }
    } catch (Throwable $e) {
        error_log('Failed to create patient confirm/reject notification: ' . $e->getMessage());
    }
}

sendSuccess($resultPayload);
