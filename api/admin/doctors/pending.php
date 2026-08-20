<?php
require_once __DIR__ . '/../../../src/response.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../src/validation/validation.php';
require_once __DIR__ . '/../../../src/auth/session.php';
require_once __DIR__ . '/../../../src/auth/middleware.php';

requireAuth('admin');

if($_SERVER['REQUEST_METHOD'] === 'GET')
{
$stmt = $pdo->prepare("SELECT doctor_profiles.id AS doctor_id, full_name
                       FROM doctor_profiles
                       WHERE approval_status = 'pending'");
$stmt->execute();

$pending_doctors = $stmt->fetchAll();

sendSuccess([
    'pending_doctors' => $pending_doctors
]);
}
else
{
    sendError(405,'Invalid request method');
}