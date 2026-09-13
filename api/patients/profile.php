<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';
require_once __DIR__ . '/../../src/auth/middleware.php';

requireAuth('patient');

if($_SERVER['REQUEST_METHOD'] === 'GET')
{
    $stmt = $pdo->prepare("SELECT patient_profiles.id AS patient_id, email, full_name, contact
                       FROM patient_profiles
                       JOIN users ON patient_profiles.user_id = users.id
                       WHERE users.id = ?");
                       
    $stmt->execute([$_SESSION['user_id']]);
    
    $profile = $stmt->fetch();

    if (!$profile) 
    {
        sendError(500, 'Profile not found');
    }

    $stmt = $pdo->prepare("SELECT id, name, type, breed, date_of_birth, photo_path FROM pets WHERE patient_id = ?");
    $stmt->execute([$profile['patient_id']]);
    $pets=$stmt->fetchAll();
    
    sendSuccess([
        'patient_profile' => [
            'patient_id' => $profile['patient_id'],
            'email' => $profile['email'],
            'full_name' => $profile['full_name'],
            'contact' => $profile['contact'],
            'pets' => $pets,
            ],
        ]);
}            
elseif($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        sendError(400, 'Invalid request');
    }

    $fullName = $input['full_name'] ?? null;
    $contact  = $input['contact']  ?? null;

    // Presence check
    $missingFields = checkRequiredFields($input, ['full_name', 'contact']);
    if (!empty($missingFields)) {
        sendError(400, 'Invalid request');
    }

    // Check existence of the patient profile
    $stmt = $pdo->prepare("SELECT id FROM patient_profiles WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $profile = $stmt->fetch();

    if (!$profile) {
        sendError(500, 'Profile not found');
    }

    // Update the patient profile
    $stmt = $pdo->prepare("UPDATE patient_profiles 
                           SET full_name = ?, contact = ? 
                           WHERE user_id = ?");
    $stmt->execute([$fullName, $contact, $_SESSION['user_id']]);

    sendSuccess(['message' => 'Profile updated successfully']);
}
else
{
    sendError(405, 'Method Not Allowed');
}
