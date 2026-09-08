<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';
require_once __DIR__ . '/../../src/auth/middleware.php';

requireAuth('patient');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') 
{
    sendError(405, 'Method Not Allowed');
}
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        sendError(400, 'Invalid request');
    }

    $petId = $input['pet_id'] ?? null;
    $name = $input['name'] ?? null;
    $type  = $input['type']  ?? null;
    $breed  = $input['breed']  ?? null;
    $dob = $input['date_of_birth']  ?? null;
    $photoPath  = $input['photo_path']  ?? null;

    // Presence check
    $missingFields = checkRequiredFields($input, ['pet_id', 'name', 'type', 'breed', 'date_of_birth']); //photo_path is optional
    if (!empty($missingFields)) {
        sendError(400, 'Invalid request');
    }

    // Verify this pet_id actually belongs to the logged-in patient (ownership check)
    $stmt = $pdo->prepare("SELECT pets.id 
                           FROM pets
                           JOIN patient_profiles ON pets.patient_id = patient_profiles.id
                           WHERE pets.id = ? AND patient_profiles.user_id = ?");
    $stmt->execute([$petId, $_SESSION['user_id']]);
    $pets = $stmt->fetch();

    if (!$pets) {
        sendError(403, 'Request Denied');
    }

    // Update the pet profile
    $stmt = $pdo->prepare("UPDATE pets 
                           SET name = ?, type = ?, breed = ?, date_of_birth = ?, photo_path = COALESCE(?, photo_path) 
                           WHERE id = ?");
                           $stmt->execute([$name, $type, $breed, $dob, $photoPath, $petId]);

    sendSuccess(['message' => 'Pets updated successfully']);
