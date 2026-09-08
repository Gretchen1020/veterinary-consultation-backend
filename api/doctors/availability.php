<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';
require_once __DIR__ . '/../../src/auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method Not Allowed');
}

requireAuth('doctor');

$userId = $_SESSION['user_id'];

// --- Fetch doctor profile + approval status, scoped to this session's user ---
$stmt = $pdo->prepare(
    'SELECT id, approval_status FROM doctor_profiles WHERE user_id = ?'
);
$stmt->execute([$userId]);
$doctor = $stmt->fetch();

if (!$doctor) {
    sendError(404, 'Doctor profile not found');
}

if ($doctor['approval_status'] !== 'approved') {
    sendError(403, 'Doctor not approved');
}

$doctorId = $doctor['id'];

// --- Validate payload ---
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || !array_key_exists('is_online', $input)) {
    sendError(422, 'is_online is required');
}

$isOnline = filter_var($input['is_online'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($isOnline === null) {
    sendError(422, 'is_online must be a boolean');
}

$isOnlineInt = $isOnline ? 1 : 0;

// --- Upsert: heartbeat stamps last_seen_at regardless of true/false ---
$stmt = $pdo->prepare(
    'INSERT INTO doctor_availability (doctor_id, is_online, last_seen_at)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        is_online = VALUES(is_online),
        last_seen_at = VALUES(last_seen_at)'
);
$stmt->execute([$doctorId, $isOnlineInt]);

// --- Return current state ---
$stmt = $pdo->prepare(
    'SELECT is_online, last_seen_at FROM doctor_availability WHERE doctor_id = ?'
);
$stmt->execute([$doctorId]);
$row = $stmt->fetch();

sendSuccess([
    'is_online'    => (bool) $row['is_online'],
    'last_seen_at' => $row['last_seen_at'],
]);