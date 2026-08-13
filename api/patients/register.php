<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';

// 1. Read and parse the raw JSON body (ONCE)
$input = json_decode(file_get_contents('php://input'), true);

if ($input === null) {
    sendError(400, 'Invalid request');
}

// 2. Pull out ALL fields 
$email    = $input['email'] ?? null;
$pin      = $input['pin']   ?? null;
$fullName = $input['full_name'] ?? null;
$contact = $input['contact'] ?? null;
$petName = $input['pet_name'] ?? null;
$petType = $input['pet_type'] ?? null;
$petBreed = $input['pet_breed'] ?? null;
$petDob = $input['pet_dob'] ?? null;


// 3. Presence check 
$missingFields = checkRequiredFields($input, ['email', 'pin', 'full_name', 'contact', 'pet_name', 'pet_type', 'pet_breed', 'pet_dob']);
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

// 6. Date format check
if (!isValidDate($petDob)) {
    sendError(400, 'Invalid request');
}

// If execution reaches here, $email and $pin are safe to use
// in the database lookup (next step).
$stmt = $pdo->prepare("SELECT id, email, pin_hash, role, failed_attempts, locked_until FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    sendError(409, 'Email already registered');
}

$pinHash = password_hash($pin, PASSWORD_DEFAULT);

// Transaction to ensure all inserts succeed or fail together
try {
    $pdo->beginTransaction();

    // Insert into users (role = 'patient') → get new user id
    $stmt = $pdo->prepare("INSERT INTO users (email, pin_hash, role) VALUES (?, ?, 'patient')");
    $stmt->execute([$email, $pinHash]);
    $userId = $pdo->lastInsertId();

    // Insert into patient_profiles (user_id, full_name, contact) → get new patient id
    $stmt = $pdo->prepare("INSERT INTO patient_profiles (user_id, full_name, contact) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $fullName, $contact]);
    $patientId = $pdo->lastInsertId();

    // Insert into pets (patient_id, name, type, breed, date_of_birth) → get new pet id
    $stmt = $pdo->prepare("INSERT INTO pets (patient_id, name, type, breed, date_of_birth) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$patientId, $petName, $petType, $petBreed, $petDob]);
    $petId = $pdo->lastInsertId();

    $pdo->commit();

    // Start the session
    startUserSession($userId, 'patient');

    sendSuccess([
        'role' => 'patient',
        'user' => [
            'id'    => $userId,
            'email' => $email,
            'patient_id' => $patientId,
            'full_name' => $fullName,
            'contact' => $contact,
            'pet_id' => $petId,
            'pet_name' => $petName,
            'pet_type' => $petType,
            'pet_breed' => $petBreed,
            'pet_dob' => $petDob,
        ],
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    //sendError(500, $e->getMessage());
    sendError(500, 'Registration failed');
}
