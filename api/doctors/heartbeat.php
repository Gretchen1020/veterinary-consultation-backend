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

// --- Fetch doctor profile + approval status ---
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

// --- Refresh last_seen_at only. Does NOT touch is_online. ---
// If no row exists yet (doctor never toggled availability), nothing to refresh.
$stmt = $pdo->prepare(
    'UPDATE doctor_availability
     SET last_seen_at = NOW()
     WHERE doctor_id = ?'
);
$stmt->execute([$doctorId]);

if ($stmt->rowCount() === 0) {
    sendError(409, 'Availability not initialized. Call availability.php first.');
}

sendSuccess(['last_seen_at' => date('Y-m-d H:i:s')]);