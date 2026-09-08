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

$stmt = $pdo->prepare(
    "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0"
);
$stmt->execute([$userId]);

sendSuccess([
    'marked_read' => $stmt->rowCount(),
]);