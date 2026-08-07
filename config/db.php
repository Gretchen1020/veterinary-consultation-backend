<?php
date_default_timezone_set('Asia/Kolkata');
// Load environment variables from .env
$lines = file(__DIR__ . '/../.env');
foreach ($lines as $line) {
    putenv(trim($line));
}

$host    = getenv('DB_HOST');
$dbname  = getenv('DB_NAME');
$user    = getenv('DB_USER');
$pass    = getenv('DB_PASS');
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}