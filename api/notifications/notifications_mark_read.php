<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/validation/validation.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method Not Allowed');
}

$userId = (int) $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$missing = checkRequiredFields($input, ['notification_id']);
if ($missing) {
    sendError(400, 'Missing required fields: ' . implode(', ', $missing));
}

$notificationId = (int) $input['notification_id'];

// Ownership check baked directly into the UPDATE's WHERE clause — a
// user can only ever mark their own notifications read, never someone
// else's by guessing an id. rowCount()===0 covers both "doesn't exist"
// and "not yours" with the same generic response, so a caller can't
// distinguish the two by probing ids.
$stmt = $pdo->prepare(
    "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?"
);
$stmt->execute([$notificationId, $userId]);

if ($stmt->rowCount() === 0) {
    sendError(404, 'Notification not found');
}

sendSuccess([
    'notification_id' => $notificationId,
    'is_read' => true,
]);