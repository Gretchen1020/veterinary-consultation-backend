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

    // *** ADDED — MySQL/MariaDB's SYSTEM timezone was confirmed resolving
    // to UTC, ~5.5hrs behind actual IST (confirmed via
    // SELECT @@global.time_zone, @@session.time_zone, NOW(); showing
    // 'SYSTEM'/'SYSTEM' and a NOW() value 5.5hrs behind the real clock).
    // date_default_timezone_set() above only affects PHP's own date
    // functions — it has zero effect on what MySQL's NOW() returns,
    // since that's governed by MySQL's own separate time_zone setting.
    //
    // Session-scoped SET (not SET GLOBAL) deliberately chosen: GLOBAL
    // requires the SUPER privilege, which shared hosting (Hostinger)
    // typically restricts. This works under any regular DB user account,
    // local or in production, with no server-config access needed.
    //
    // Numeric offset '+05:30' used instead of the named zone
    // 'Asia/Kolkata' — named zones require MySQL's timezone tables to be
    // preloaded via mysql_tzinfo_to_sql, a separate setup step most
    // installs (including fresh Hostinger databases) haven't done. The
    // numeric offset needs no such setup. India has no DST, so this is
    // correct year-round with no seasonal adjustment ever needed.
    //
    // Runs once per request here in config/db.php, which every endpoint
    // already require_once's before running any query — so every NOW()
    // call in every file is corrected by this one line, without needing
    // to touch each individual query.
    $pdo->exec("SET time_zone = '+05:30'");
 
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}