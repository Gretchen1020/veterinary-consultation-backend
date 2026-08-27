<?php
require_once __DIR__ . '/../src/response.php';
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
/*
$basePath = '/wingtrix_project/public';
if (str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath));
}
*/
$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath !== '/' && str_starts_with($requestPath, $basePath)) {

    $requestPath = substr($requestPath, strlen($basePath));
}

$routes = [
    'GET /api/health'  => __DIR__ . '/../api/health.php',
    'POST /api/auth/login'  => __DIR__ . '/../api/auth/login.php',
    'POST /api/auth/logout'  => __DIR__ . '/../api/auth/logout.php',
    'POST /api/patients/register'  => __DIR__ . '/../api/patients/register.php',
    'GET /api/patients/profile'  => __DIR__ . '/../api/patients/profile.php',
    'POST /api/patients/profile' => __DIR__ . '/../api/patients/profile.php',
    'POST /api/patients/pets' => __DIR__ . '/../api/patients/pets.php',
    'POST /api/doctors/register'  => __DIR__ . '/../api/doctors/register.php',
    'GET /api/admin/doctors/pending'        => __DIR__ . '/../api/admin/doctors/pending.php',
    'GET /api/admin/doctors/detail'         => __DIR__ . '/../api/admin/doctors/detail.php',
    'GET /api/admin/doctors/document'       => __DIR__ . '/../api/admin/doctors/document.php',
    'POST /api/admin/doctors/update-status' => __DIR__ . '/../api/admin/doctors/update-status.php',
    'GET /api/doctors/list'          => __DIR__ . '/../api/doctors/list.php',
    'POST /api/doctors/availability' => __DIR__ . '/../api/doctors/availability.php',
    'GET /api/doctors/heartbeat'     => __DIR__ . '/../api/doctors/heartbeat.php',
    'GET /api/wallet/details'   => __DIR__ . '/../api/wallet/details.php',
    'POST /api/wallet/recharge' => __DIR__ . '/../api/wallet/recharge.php',
    'GET /api/admin/settings'  => __DIR__ . '/../api/admin/settings.php',
    'POST /api/admin/settings' => __DIR__ . '/../api/admin/settings.php',
    'POST /api/chat/request' => __DIR__ . '/../api/chat/request.php',
    'POST /api/chat/respond' => __DIR__ . '/../api/chat/respond.php',
    'GET /api/chat/messages'  => __DIR__ . '/../api/chat/messages.php',
    'POST /api/chat/messages' => __DIR__ . '/../api/chat/messages.php',
    'GET /api/chat/session'  => __DIR__ . '/../api/chat/session.php',
    'POST /api/chat/session' => __DIR__ . '/../api/chat/session.php',
    'GET /src/billing/close_stale_sessions' => __DIR__ . '/../src/billing/close_stale_sessions.php'

];

$key = "$method $requestPath";

if (isset($routes[$key])) {
    require $routes[$key];
} 
else {
    sendError(404, 'Not Found');
}