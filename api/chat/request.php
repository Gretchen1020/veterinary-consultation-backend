<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/patient_profile.php';
require_once __DIR__ . '/../../src/validation/validation.php';

requireAuth('patient');

$patientId = getPatientProfileId($pdo, $_SESSION['user_id']);
if ($patientId === null) {
    sendError(404, 'Patient profile not found');
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$missing = checkRequiredFields($input, ['doctor_id', 'pet_id']);
if ($missing) {
    sendError(400, 'Missing required fields: ' . implode(', ', $missing));
}

$doctorId = (int) $input['doctor_id'];
$petId    = (int) $input['pet_id'];

// T-02-style ownership check: pet must belong to this patient
$stmt = $pdo->prepare("SELECT id FROM pets WHERE id = ? AND patient_id = ?");
$stmt->execute([$petId, $patientId]);
if (!$stmt->fetch()) {
    sendError(403, 'Pet does not belong to this patient');
}

// Doctor must exist, be approved, and currently online
$stmt = $pdo->prepare(
    "SELECT dp.id, da.is_online
     FROM doctor_profiles dp
     LEFT JOIN doctor_availability da ON da.doctor_id = dp.id
     WHERE dp.id = ? AND dp.approval_status = 'approved'"
);
$stmt->execute([$doctorId]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    sendError(404, 'Doctor not found or not approved');
}
if ((int) ($doctor['is_online'] ?? 0) !== 1) {
    sendError(409, 'Doctor is currently offline');
}

// Prevent duplicate pending requests to the same doctor for the same pet
$stmt = $pdo->prepare(
    "SELECT id FROM chat_requests
     WHERE patient_id = ? AND doctor_id = ? AND pet_id = ? AND status = 'pending'"
);
$stmt->execute([$patientId, $doctorId, $petId]);
if ($stmt->fetch()) {
    sendError(409, 'A pending request already exists for this doctor and pet');
}

$stmt = $pdo->prepare(
    "INSERT INTO chat_requests (patient_id, doctor_id, pet_id, status, requested_at)
     VALUES (?, ?, ?, 'pending', NOW())"
);
$stmt->execute([$patientId, $doctorId, $petId]);

sendSuccess([
    'request_id' => (int) $pdo->lastInsertId(),
    'status'     => 'pending',
]);