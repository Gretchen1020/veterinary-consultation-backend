<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/patient_profile.php';
require_once __DIR__ . '/../../src/auth/doctor_profile.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/notifications/notification_service.php';

requireAuth('doctor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') 
{
    sendError(405, 'Method Not Allowed');
}

$doctorId = getDoctorProfileId($pdo, $_SESSION['user_id']);
if ($doctorId === null) {
    sendError(404, 'Doctor profile not found');
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$missing = checkRequiredFields($input, ['request_id', 'action']);
if ($missing) {
    sendError(400, 'Missing required fields: ' . implode(', ', $missing));
}

$requestId = (int) $input['request_id'];
$action    = $input['action'];

if (!isValidEnum($action, ['accept', 'reject'])) {
    sendError(400, 'action must be accept or reject');
}

$sessionId = null;
$rate = null;
$patientUserId = null;

// *** ADDED — whole transaction now wrapped in a catch-all try/catch.
// Previously, only ANTICIPATED failures (request not found, wrong
// doctor, already responded, no active settings) had explicit inline
// rollBack()+sendError() calls — there was no safety net for an
// UNANTICIPATED failure (a deadlock, a constraint violation, a dropped
// connection) during the actual UPDATE/INSERT calls. Without this,
// such a failure would propagate as an uncaught exception instead of
// the API's normal {"Error": "..."} response shape. Matches the
// pattern register.php/update-status.php already use.
//
// Both possible commit() points (reject's early exit, accept's later
// one) now sit inside this single try, since there's only ever one
// beginTransaction() and exactly one of the two paths executes.
try {
    // Row-lock the request to avoid a double-accept race (same pattern as Day 6 update-status.php)
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM chat_requests WHERE id = ? FOR UPDATE");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $pdo->rollBack();
        sendError(404, 'Request not found');
    }

    // T-09: only the doctor this request was sent to may respond
    if ((int) $request['doctor_id'] !== $doctorId) {
        $pdo->rollBack();
        sendError(403, 'This request does not belong to you');
    }

    if ($request['status'] !== 'pending') {
        $pdo->rollBack();
        sendError(409, 'Request has already been responded to');
    }

    // Resolve the patient's actual users.id for notification purposes.
    // Deliberately a SEPARATE, unlocked query rather than joining this
    // onto the FOR UPDATE select above — as it would have locked matching rows 
    // in EVERY joined table, not just chat_requests, so joining patient_profiles 
    // here would add lock contention to a table this race-prevention query has nothing to do with.
    $patientLookup = $pdo->prepare("SELECT user_id FROM patient_profiles WHERE id = ?");
    $patientLookup->execute([(int) $request['patient_id']]);
    $patientUserId = (int) $patientLookup->fetchColumn();

    if ($action === 'reject') {
        $stmt = $pdo->prepare(
            "UPDATE chat_requests SET status = 'rejected', responded_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$requestId]);
        $pdo->commit();

    }
    else {
        // action === 'accept'
        $stmt = $pdo->prepare("SELECT id, rate_per_minute FROM admin_settings WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $activeSettings = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activeSettings === false) {
            $pdo->rollBack();
            sendError(500, 'No active platform settings configured');
        }

        $rate = $activeSettings['rate_per_minute'];
        $adminSettingsId = (int) $activeSettings['id'];

        $stmt = $pdo->prepare(
            "UPDATE chat_requests SET status = 'accepted', responded_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$requestId]);

        $stmt = $pdo->prepare(
            "INSERT INTO chat_sessions (request_id, status, started_at, rate_per_minute, admin_settings_id)
             VALUES (?, 'active', NOW(), ?, ?)"
        );
        $stmt->execute([$requestId, $rate, $adminSettingsId]);
        $sessionId = (int) $pdo->lastInsertId();

        // Day 12: create the billing_records row up front as 'pending'.
        // finalizeBilling() (src/billing/billing_service.php) only ever UPDATEs
        // this row at session end — it does not insert. Creating it here,
        // inside the same transaction as the chat_sessions insert, means every
        // active session has exactly one billing_records row for its whole
        // lifetime, and the UNIQUE(session_id) constraint added in the Day 12
        // migration guards against ever creating a second one.
        $billingInsert = $pdo->prepare(
            "INSERT INTO billing_records
                (session_id, duration_seconds, rate_per_minute, gross_amount, commission_amount, doctor_amount, billing_status)
             VALUES (?, 0, ?, 0, 0, 0, 'pending')"
        );
        $billingInsert->execute([$sessionId, $rate]);

        $pdo->commit();
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendError(500, 'Failed to process request');
}

// *** Notification + response now sit OUTSIDE the try/catch above —
// same fix as register.php/update-status.php: nothing after commit()
// should be inside a block whose catch calls rollBack().
if ($action === 'reject') {

    // FLAGGED: generic message, no doctor name — naming the doctor
    // would need a new query (full_name isn't fetched anywhere in this
    // file), same tradeoff already made in request.php. Skipped rather
    // than adding a lookup purely for cosmetic text.
    try {
        createNotification(
            $pdo,
            $patientUserId,
            'patient',
            'CHAT_REQUEST_REJECTED',
            'Consultation request declined',
            'Your consultation request was declined.',
            'chat_request',
            $requestId
        );
    } catch (Throwable $e) {
        error_log('Failed to create CHAT_REQUEST_REJECTED notification: ' . $e->getMessage());
    }

    sendSuccess(['request_id' => $requestId, 'status' => 'rejected']);

} else {

    // reference_type points at the new chat_session, not the
    // now-superseded chat_request — the patient's next real action is
    // entering the session, so that's the more useful destination for
    // a future clickable notification.
    try {
        createNotification(
            $pdo,
            $patientUserId,
            'patient',
            'CHAT_REQUEST_ACCEPTED',
            'Consultation request accepted',
            'Your consultation request has been accepted.',
            'chat_session',
            $sessionId
        );
    } catch (Throwable $e) {
        error_log('Failed to create CHAT_REQUEST_ACCEPTED notification: ' . $e->getMessage());
    }

    sendSuccess([
        'request_id'      => $requestId,
        'status'          => 'accepted',
        'session_id'      => $sessionId,
        'rate_per_minute' => (float) $rate,
    ]);
}