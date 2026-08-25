<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/patient_profile.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/doctor_profile.php';

requireAuth('doctor');

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

if ($action === 'reject') {
    $stmt = $pdo->prepare(
        "UPDATE chat_requests SET status = 'rejected', responded_at = NOW() WHERE id = ?"
    );
    $stmt->execute([$requestId]);
    $pdo->commit();

    sendSuccess(['request_id' => $requestId, 'status' => 'rejected']);
}

// action === 'accept'
$stmt = $pdo->prepare("SELECT rate_per_minute FROM admin_settings WHERE is_active = 1 LIMIT 1");
$stmt->execute();
$rate = $stmt->fetchColumn();

if ($rate === false) {
    $pdo->rollBack();
    sendError(500, 'No active platform settings configured');
}

$stmt = $pdo->prepare(
    "UPDATE chat_requests SET status = 'accepted', responded_at = NOW() WHERE id = ?"
);
$stmt->execute([$requestId]);

$stmt = $pdo->prepare(
    "INSERT INTO chat_sessions (request_id, status, started_at, rate_per_minute)
     VALUES (?, 'active', NOW(), ?)"
);
$stmt->execute([$requestId, $rate]);
$sessionId = (int) $pdo->lastInsertId();

$pdo->commit();

sendSuccess([
    'request_id'      => $requestId,
    'status'          => 'accepted',
    'session_id'      => $sessionId,
    'rate_per_minute' => (float) $rate,
]);