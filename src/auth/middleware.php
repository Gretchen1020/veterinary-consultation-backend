<?php
require_once __DIR__ . '/../response.php';
function requireAuth() {
    session_start();

    if (!isset($_SESSION['user_id'])) {
        sendError(401,'Authentication Required'); //Request Unauthorized
    }
}