<?php
require_once __DIR__ . '/../../../src/response.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../src/validation/validation.php';
require_once __DIR__ . '/../../../src/auth/session.php';
require_once __DIR__ . '/../../../src/auth/middleware.php';

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') 
{  
    sendError(405, 'Method Not Allowed');
}

$doctorId = $_GET['doctor_id'] ?? null;

    // Validate presence + numeric — never trust client-supplied IDs
    if ($doctorId === null || !ctype_digit((string)$doctorId)) {
        sendError(400, 'Invalid or missing doctor_id');
    }

    $stmt = $pdo->prepare("SELECT doctor_profiles.id AS doctor_id, full_name, degree, 
                                   specialization, about, contact, profile_photo, 
                                   approval_status, doctor_profiles.created_at, email
                            FROM doctor_profiles
                            JOIN users ON doctor_profiles.user_id = users.id
                            WHERE doctor_profiles.id = ?");
    $stmt->execute([$doctorId]);
    $doctor = $stmt->fetch();

    if (!$doctor) {
        sendError(404, 'Doctor not found');
    }

    // Only id + type — file_path/mime_type stay hidden behind document.php
    $stmt = $pdo->prepare("SELECT id AS document_id, document_type 
                            FROM doctor_documents 
                            WHERE doctor_id = ?");
    $stmt->execute([$doctorId]);
    $documents = $stmt->fetchAll();

    $doctor['documents'] = $documents;

    sendSuccess([
        'doctor_detail' => $doctor
    ]);