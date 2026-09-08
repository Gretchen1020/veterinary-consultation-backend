<?php
require_once __DIR__ . '/../../../src/response.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../src/validation/validation.php';
require_once __DIR__ . '/../../../src/auth/session.php';
require_once __DIR__ . '/../../../src/auth/middleware.php';

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET')
{
    sendError(405, 'Method Not Allowed');
}
    $allowedStatuses = ['pending', 'approved', 'rejected'];
    $status = $_GET['status'] ?? 'pending';

    if (!in_array($status, $allowedStatuses, true)) {
        sendError(400, 'Invalid status filter');
        exit;
    }

    $stmt = $pdo->prepare("SELECT doctor_profiles.id AS doctor_id, full_name
                           FROM doctor_profiles
                           WHERE approval_status = :status");
    $stmt->execute(['status' => $status]);

    $pending_doctors = $stmt->fetchAll();

    sendSuccess([
        'status_filter' => $status,
        'pending_doctors' => $pending_doctors
    ]);