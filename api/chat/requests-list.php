<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';
require_once __DIR__ . '/../../src/auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

// Join to patient + pet info so the doctor sees who's asking and about
// which pet, not just a bare request_id. Always pending only — accepted
// requests become chat_sessions (out of scope here), rejected requests
// are terminal and have nowhere else meaningful to be listed.
$stmt = $pdo->prepare(
    "SELECT
        cr.id AS request_id,
        cr.patient_id,
        pp.full_name AS patient_name,
        p.id AS pet_id,
        p.name AS pet_name,
        p.type AS pet_type,
        cr.requested_at
    FROM chat_requests cr
    JOIN patient_profiles pp ON pp.id = cr.patient_id
    JOIN pets p ON p.id = cr.pet_id
    WHERE cr.doctor_id = ?
      AND cr.status = 'pending'
    ORDER BY cr.requested_at ASC"
);

$stmt->execute([$doctorId]);
$requests = $stmt->fetchAll();

sendSuccess([
    'requests' => $requests,
]);