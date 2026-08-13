<?php
function startUserSession(int $userId, string $role): void {
    if(session_status() === PHP_SESSION_NONE) {
        session_start();
    }
session_regenerate_id(true); // prevents session fixation — new session ID after login
$_SESSION['user_id'] = $userId;
$_SESSION['role']    = $role;
}