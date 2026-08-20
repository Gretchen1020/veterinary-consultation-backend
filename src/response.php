<?php
function sendError($statusCode, $message) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['Error' => $message]);
    exit;
}
function sendSuccess($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}