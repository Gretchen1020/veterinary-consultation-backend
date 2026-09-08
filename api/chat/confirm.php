<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/patient_profile.php';
require_once __DIR__ . '/../../src/validation/validation.php';

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

$pdo->beginTransaction();

try {
    
    // Lock the session row while confirming it.
    // This prevents two confirmation requests from racing.
    
    $stmt = $pdo->prepare(
        "SELECT
            cs.id,
            cs.request_id,
            cs.status,
            cs.started_at,
            cs.confirmed_at,
            cr.patient_id
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

    // Ownership check:
    // only the patient who owns the request may confirm it.

    if ((int) $session['patient_id'] !== $patientId) {
        $pdo->rollBack();
        sendError(403, 'This session does not belong to you');
    }

    // A session must still be active to be confirmed.
   
    if ($session['status'] !== 'active') {
        $pdo->rollBack();
        sendError(409, 'Session has already ended');
    }

    // Confirmation is intentionally idempotency-protected.
    // Once confirmed, it cannot be confirmed again.
   
    if ($session['confirmed_at'] !== null) {
        $pdo->rollBack();
        sendError(409, 'Session has already been confirmed');
    }
   
     //This is the actual billing start point.
    // started_at:
    //   Starts the billing clock.

    // confirmed_at:
    //   Records when the patient confirmed.

    // last_heartbeat_at:
    //   Must remain NULL so the first heartbeat calculates
    //   elapsed time from the newly-set started_at.
  
    $stmt = $pdo->prepare(
        "UPDATE chat_sessions
         SET
            confirmed_at = NOW(),
            started_at = NOW(),
            last_heartbeat_at = NULL
         WHERE id = ?
           AND confirmed_at IS NULL"
    );

    $stmt->execute([$sessionId]);

    $pdo->commit();

    $stmt = $pdo->prepare(
    "SELECT confirmed_at, started_at FROM chat_sessions WHERE id = ?"
);
$stmt->execute([$sessionId]);
$updated = $stmt->fetch(PDO::FETCH_ASSOC);

sendSuccess([
    'session_id'   => $sessionId,
    'status'       => 'active',
    'confirmed'    => true,
    'confirmed_at' => $updated['confirmed_at'],
    'started_at'   => $updated['started_at'], // worth including — frontend likely wants this for a client-side timer
]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    sendError(500, 'Failed to confirm session');
}