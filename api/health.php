<?php
// api/health.php

header('Content-Type: application/json');

require __DIR__ . '/../config/db.php';

try {
    $pdo->query('SELECT 1');
    echo json_encode([
        'status'   => "ok",   
        'database' => "connected",
        'timestamp' => date('c'),  // ISO 8601 format
    ]);
} 
catch (Exception $e) 
{
    http_response_code(500);
    echo json_encode([
        'status' => "error",
        'database' => "disconnected",
        'message' => $e->getMessage(),
    ]);
}