<?php
function emailExists(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetchColumn() > 0;
}