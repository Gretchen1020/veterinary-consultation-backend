<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/patient_profile.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/notifications/notification_service.php';

requireAuth('patient');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') 
{
    sendError(405, 'Method Not Allowed');
}

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

// Doctor must exist, be approved, and currently online.
// ADDED: dp.user_id — additive to this existing query, resolves the
// doctor's actual users.id for the notification below. doctor_id
// everywhere else in this file/table means doctor_profiles.id, which
// is NOT usable as a notification recipient (notifications.user_id
// references users.id).
$stmt = $pdo->prepare(
    "SELECT dp.id, dp.user_id, da.is_online
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
$requestId = (int) $pdo->lastInsertId();

// *** ADDED — doctor notification ***
// "after the INSERT has actually succeeded" — same pattern
// as enquiries.php. Wrapped defensively: a broken notification must
// never block a request the patient genuinely just created.
//
// FLAGGED: message is generic ("a new consultation request") rather
// than naming the patient, unlike the original notification-matrix
// example ("New consultation request from [patient]"). Adding the
// patient's name would need a new query (patient_profiles.full_name
// isn't fetched anywhere in this file) — left out for now rather than
// adding a DB roundtrip purely for cosmetic message text. Easy to add
// later if wanted.
try {
    createNotification(
        $pdo,
        (int) $doctor['user_id'],
        'doctor',
        'NEW_CHAT_REQUEST',
        'New consultation request',
        'You have received a new consultation request.',
        'chat_request',
        $requestId
    );
} catch (Throwable $e) {
    error_log('Failed to create NEW_CHAT_REQUEST notification: ' . $e->getMessage());
}

sendSuccess([
    'request_id' => $requestId,
    'status'     => 'pending',
]);