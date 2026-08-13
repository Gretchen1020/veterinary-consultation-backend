<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';

// 1. Read and parse the raw JSON body
$input = json_decode(file_get_contents('php://input'), true);

if ($input === null) {
    sendError(400, 'Invalid request');
}

// 2. Pull out the fields, defaulting to null if missing
$email = $input['email'] ?? null;
$pin   = $input['pin']   ?? null;

// 3. Presence check
$missingFields = checkRequiredFields($input, ['email', 'pin']);
if (!empty($missingFields)) {
    sendError(400, 'Invalid request');
}

// 4. Email format check
if (!isValidEmail($email)) {
    sendError(400, 'Invalid request');
}

// 5. PIN format check — exactly 4 digits
if (!isValidPin($pin)) {
    sendError(400, 'Invalid request');
}

// If execution reaches here, $email and $pin are safe to use
// in the database lookup (next step).
$stmt = $pdo->prepare("SELECT id, email, pin_hash, role, failed_attempts, locked_until FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    sendError(401, 'Invalid credentials');
}

//locked account check
if ($user['locked_until'] !== null) {
    $lockedUntil = new DateTime($user['locked_until']);
    $now = new DateTime();

    if ($lockedUntil > $now) {
        sendError(401, 'Invalid credentials');
    }
}

// Verify the PIN
if (!password_verify($pin, $user['pin_hash'])) {
    // Increment failed attempts
    $failedAttempts = $user['failed_attempts'] + 1;
    $lockedUntil = null;

    // Lock the account if there are 5 or more failed attempts
    if ($failedAttempts >= 5) {
        $lockedUntil = (new DateTime())->modify('+15 minutes')->format('Y-m-d H:i:s');
        $failedAttempts = 0; // Reset failed attempts after locking
    }

    // Update the database with the new failed attempts and locked_until values
    $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?");
    $updateStmt->execute([$failedAttempts, $lockedUntil, $user['id']]);

    sendError(401, 'Invalid credentials');
}
else {
    // Reset failed attempts and locked_until on successful login
    $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?");
    $updateStmt->execute([$user['id']]);

    // Start the session
    startUserSession($user['id'], $user['role']);

    sendSuccess([
        'role' => $user['role'],
        'user' => [
            'id'    => $user['id'],
            'email' => $user['email'],
        ],
    ]);
    }