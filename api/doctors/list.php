<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';
require_once __DIR__ . '/../../src/auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method Not Allowed');
}

// Public/Patient endpoint — no requireAuth() call, open to anyone

const STALENESS_MINUTES = 5;

// --- Lazy-write pass: force stale "online" doctors offline first ---
$staleStmt = $pdo->prepare(
    'UPDATE doctor_availability
     SET is_online = 0
     WHERE is_online = 1
       AND last_seen_at <= (NOW() - INTERVAL 5 MINUTE)'
);
$staleStmt->execute();

// --- Read filters ---
$search         = trim($_GET['search'] ?? '');
$specialization = trim($_GET['specialization'] ?? '');
$onlineOnly     = filter_var($_GET['online'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

$conditions = ["dp.approval_status = 'approved'"];
$params = [];

if ($search !== '') {
    $conditions[] = 'dp.full_name LIKE :search';
    $params['search'] = '%' . $search . '%';
}

if ($specialization !== '') {
    $conditions[] = 'dp.specialization = :specialization';
    $params['specialization'] = $specialization;
}

if ($onlineOnly === true) {
    $conditions[] = 'COALESCE(da.is_online, 0) = 1';
}

$whereClause = implode(' AND ', $conditions);

$sql = "SELECT
            dp.id,
            dp.full_name,
            dp.specialization,
            dp.experience,
            dp.profile_photo,
            dp.about,
            COALESCE(da.is_online, 0) AS is_online
        FROM doctor_profiles dp
        LEFT JOIN doctor_availability da ON da.doctor_id = dp.id
        WHERE {$whereClause}
        ORDER BY is_online DESC, dp.full_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$doctors = $stmt->fetchAll();

$result = array_map(function ($doc) {
    return [
        'id'             => (int) $doc['id'],
        'full_name'      => $doc['full_name'],
        'specialization' => $doc['specialization'],
        'experience'     => $doc['experience'],
        'profile_photo'  => $doc['profile_photo'],
        'about'          => $doc['about'],
        'is_online'      => (bool) $doc['is_online'],
    ];
}, $doctors);

sendSuccess(['doctors' => $result]);