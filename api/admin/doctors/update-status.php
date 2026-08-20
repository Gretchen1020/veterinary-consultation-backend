<?php
require_once __DIR__ . '/../../../src/response.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../src/validation/validation.php';
require_once __DIR__ . '/../../../src/auth/session.php';
require_once __DIR__ . '/../../../src/auth/middleware.php';

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        sendError(400, 'Invalid request');
    }

    $missingFields = checkRequiredFields($input, ['doctor_id', 'status', 'remark']);
    if (!empty($missingFields)) {
        sendError(400, 'Invalid request');
    }

    $doctorId = $input['doctor_id'];
    $status   = $input['status'];   // expected: 'approved' or 'rejected'
    $remark   = $input['remark'];

    if (!ctype_digit((string)$doctorId)) {
        sendError(400, 'Invalid doctor_id');
    }

    // Only two legal outcomes — reject anything else outright
    if (!in_array($status, ['approved', 'rejected'], true)) {
        sendError(400, 'Invalid status value');
    }

    $adminId = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();

        // Lock in current state check inside the transaction to avoid a race
        // between two admins acting on the same doctor at the same time
        $stmt = $pdo->prepare("SELECT approval_status FROM doctor_profiles WHERE id = ? FOR UPDATE");
        $stmt->execute([$doctorId]);
        $doctor = $stmt->fetch();

        if (!$doctor) {
            $pdo->rollBack();
            sendError(404, 'Doctor not found');
        }

        if ($doctor['approval_status'] !== 'pending') {
            $pdo->rollBack();
            sendError(409, 'Doctor application has already been decided');
        }

        $stmt = $pdo->prepare("UPDATE doctor_profiles SET approval_status = ? WHERE id = ?");
        $stmt->execute([$status, $doctorId]);

        $stmt = $pdo->prepare("INSERT INTO doctor_approvals (doctor_id, admin_id, decision, remark, decided_at) 
                                VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$doctorId, $adminId, $status, $remark]);

        $pdo->commit();

        sendSuccess(['doctor_id'  => $doctorId,
                    'approval_status'     => $status,
                    'remark'     => $remark,]);

    } catch (Exception $e) {
        $pdo->rollBack();
        sendError(500, 'Failed to update doctor status');
    }
}
else
{
    sendError(405, 'Invalid request method');
}