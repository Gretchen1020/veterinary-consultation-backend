<?php 
 
require_once __DIR__ . '/../../config/db.php'; 
require_once __DIR__ . '/../../src/response.php'; 
require_once __DIR__ . '/../../src/auth/middleware.php'; 
require_once __DIR__ . '/../../src/validation/validation.php'; 
 
requireAuth('admin'); 
 
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { 
    sendError(405, 'Method not allowed'); 
} 
 
// Registered patients — one row per patient created at registration (BE-03) 
$stmt = $pdo->query("SELECT COUNT(*) FROM patient_profiles"); 
$registeredPatients = (int) $stmt->fetchColumn(); 
 
// Doctor applications awaiting admin decision 
$stmt = $pdo->query( 
    "SELECT COUNT(*) 
     FROM doctor_profiles 
     WHERE approval_status = 'pending'" 
); 
$pendingDoctorApplications = (int) $stmt->fetchColumn();  

// Approved doctor applications
$stmt = $pdo->query( 
    "SELECT COUNT(*) 
     FROM doctor_profiles 
     WHERE approval_status = 'approved'" 
); 
$approvedDoctorApplications = (int) $stmt->fetchColumn();   

// Support enquiries 
$stmt = $pdo->query( 
    "SELECT COUNT(*) 
     FROM support_enquiries 
     WHERE status = 'open'" 
); 
$unresolvedQueries = (int) $stmt->fetchColumn();  

sendSuccess([ 
    'registered_patients'          => $registeredPatients, 
    'pending_doctor_applications'  => $pendingDoctorApplications,
    'approved_doctor_applications' => $approvedDoctorApplications,
    'unresolved_queries'            => $unresolvedQueries
]);