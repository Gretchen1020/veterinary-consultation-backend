<?php
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$basePath = '/wingtrix_project/public';
if (str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath));
}

$routes = [
    'GET /api/health'  => __DIR__ . '/../api/health.php',
    'POST /api/auth/login'  => __DIR__ . '/../api/auth/login.php',
    'POST /api/auth/logout'  => __DIR__ . '/../api/auth/logout.php',
];

$key = "$method $requestPath";

if (isset($routes[$key])) {
    require $routes[$key];
} 
else {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
}