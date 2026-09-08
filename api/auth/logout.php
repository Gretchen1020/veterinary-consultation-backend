<?php
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/response.php';

requireAuth(); // Check if the user is authenticated

if ($_SERVER['REQUEST_METHOD'] !== 'POST') 
{
    sendError(405, 'Method Not Allowed');
}

$_SESSION = array(); // Unset all variables

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    // Delete the cookie
    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
}
session_destroy(); // Destroy the session on the server
sendSuccess(['message' => 'Logged out successfully']); // Send a success response