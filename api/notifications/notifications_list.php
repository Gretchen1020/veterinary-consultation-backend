<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/validation/validation.php';

// Any authenticated role — a notification's user_id already scopes it
// to the right recipient, same precedent as session.php's bare
// requireAuth() for "any authenticated participant."
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method Not Allowed');
}

$userId = (int) $_SESSION['user_id'];

// Optional ?unread_only=1 to fetch just the unread ones, e.g. for a
// badge count without pulling full history every poll.
$unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';

$sql = "SELECT id, type, title, message, reference_type, reference_id, is_read, created_at
        FROM notifications
        WHERE user_id = ?";

if ($unreadOnly) {
    $sql .= " AND is_read = 0";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

sendSuccess([
    'notifications' => $notifications,
]);