<?php
require_once __DIR__ . '/../response.php';
function requireAuth(?string $requiredRole = null) {
    session_start();

    if (!isset($_SESSION['user_id'])) {
        sendError(401, 'Authentication Required');
    }

    if ($requiredRole !== null && $_SESSION['role'] !== $requiredRole) {
        sendError(403, 'Forbidden'); // logged in, but wrong role
    }
}