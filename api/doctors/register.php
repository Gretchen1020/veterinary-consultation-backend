<?php
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';
require_once __DIR__ . '/../../src/auth/userslookup.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method Not Allowed');
}
    
// 2. Pull out ALL fields 
$email = $_POST['email'] ?? null;
$pin      = $_POST['pin']   ?? null;
$fullName = $_POST['full_name'] ?? null;
$degree = $_POST['degree'] ?? null;
$specialization = $_POST['specialization'] ?? null;
$experience = $_POST['experience'] ?? null;
$contact = $_POST['contact'] ?? null;
$about = $_POST['about'] ?? null;     //optional field

$degreeCertOriginalName = $_FILES['degree_certificate']['name'] ?? null;
$idProofOriginalName = $_FILES['id_proof']['name'] ?? null;

// 3. Presence check 
$missingFields = checkRequiredFields($_POST, ['email', 'pin', 'full_name', 'degree', 'specialization', 'experience', 'contact']);
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

if (!isValidExperience($experience)) {
    sendError(400, 'Invalid request');
}

if (!isValidContact($contact)) {
    sendError(400, 'Invalid request');
}
// If execution reaches here, $email and $pin are safe to use
//Check if email already exists (db lookup) 
if (emailExists($pdo, $email)) { 
    sendError(409, 'Email already registered');
    }

$pinHash = password_hash($pin, PASSWORD_DEFAULT);

// degree_certificate
$degreeError = validateUploadedFile(
    'degree_certificate',
    5 * 1024 * 1024,                                // 5MB
    ['application/pdf', 'image/jpeg', 'image/png'],
    false                                          // required
);
if ($degreeError['error'] !== null) {
    sendError(400, $degreeError['error']);
}
else {
    $degreeMimeType = $degreeError['mime_type'];
}

// id_proof
$idProofError = validateUploadedFile(
    'id_proof',
    5 * 1024 * 1024,                                 // 5MB
    ['application/pdf', 'image/jpeg', 'image/png'],
    false                                           // required
);
if ($idProofError['error'] !== null) {
    sendError(400, $idProofError['error']);
}
else {
    $idProofMimeType = $idProofError['mime_type'];
}

// profile_photo
$profilePhotoError = validateUploadedFile(
    'profile_photo',
    2 * 1024 * 1024,             // 2MB
    ['image/jpeg', 'image/png'],
    true                        // optional
);
if ($profilePhotoError['error'] !== null) {
    sendError(400, $profilePhotoError['error']);
}
else {
    $profilePhotoMimeType = $profilePhotoError['mime_type'];
}


$degreeUpload = uploadFile('degree_certificate', '../../storage/doctor_documents','_degree_cert', $degreeMimeType);
if($degreeUpload['error'] !== null) {
    sendError(500, $degreeUpload['error']);
}
$uploadedFilePaths[] = $degreeUpload['file_path'];

$idProofUpload = uploadFile('id_proof', '../../storage/doctor_documents', '_id_proof', $idProofMimeType);
if ($idProofUpload['error'] !== null) {

     foreach ($uploadedFilePaths as $path) {
        unlink($path);
    }
        
    sendError(500, $idProofUpload['error']);
}
$uploadedFilePaths[] = $idProofUpload['file_path'];

if ($profilePhotoMimeType !== null){     // since profile photo is optional, we only upload if it was provided
    $profilePhotoUpload = uploadFile('profile_photo', '../../storage/doctor_documents', '_profile_photo', $profilePhotoMimeType);

    if ($profilePhotoUpload['error'] !== null) {
         foreach ($uploadedFilePaths as $path) {
            unlink($path);
         }
        sendError(500, $profilePhotoUpload['error']);
    }

    $uploadedFilePaths[] = $profilePhotoUpload['file_path'];  // reached only on success — always tracked for rollback cleanup
}
else{
    $profilePhotoUpload['file_path'] = null;  // No profile photo uploaded, nothing to track
}


// Transaction to ensure all inserts succeed or fail together
try {
    $pdo->beginTransaction();

    // Insert into users (role = 'doctor') → get new user id
    $stmt = $pdo->prepare("INSERT INTO users (email, pin_hash, role) VALUES (?, ?, 'doctor')");
    $stmt->execute([$email, $pinHash]);
    $userId = $pdo->lastInsertId();

    // Insert into doctor_profiles (user_id, full_name, degree, specialization, experience, about, contact, profile_photo, approval_status) → get new doctor id
    $stmt = $pdo->prepare("INSERT INTO doctor_profiles (user_id, full_name, degree, specialization, experience, about, contact, profile_photo, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $fullName, $degree, $specialization, $experience, $about, $contact, $profilePhotoUpload['file_path'], 'pending']);
    $doctorId = $pdo->lastInsertId();

    // Insert into doctor_documents for degree_certificate (doctor_id, document_type, file_path, original_name, mime_type) → get new doctor_documents id
    $stmt = $pdo->prepare("INSERT INTO doctor_documents (doctor_id, document_type, file_path, original_name, mime_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$doctorId, 'degree_certificate', $degreeUpload['file_path'], $degreeCertOriginalName, $degreeMimeType]);
    $documentId = $pdo->lastInsertId();

    // Insert into doctor_documents for id_proof (doctor_id, document_type, file_path, original_name, mime_type) → get new doctor_documents id
    $stmt = $pdo->prepare("INSERT INTO doctor_documents (doctor_id, document_type, file_path, original_name, mime_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$doctorId, 'id_proof', $idProofUpload['file_path'], $idProofOriginalName, $idProofMimeType]);
    $documentId = $pdo->lastInsertId();

    $pdo->commit();

    // Start the session
    startUserSession($userId, 'doctor');

    sendSuccess([
        'role' => 'doctor',
        'user' => [
            'id'    => $userId,
            'email' => $email,
            'doctor_id' => $doctorId,
            'full_name' => $fullName,
            'degree' => $degree,
            'specialization' => $specialization,
            'experience' => $experience,
            'about' => $about,
            'contact' => $contact,
             'approval_status' => 'pending',
        ],
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    foreach ($uploadedFilePaths as $path) {
        unlink($path);
    }
    //sendError(500, $e->getMessage());
    sendError(500, 'Registration failed');
}
