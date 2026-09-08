<?php
require_once __DIR__ . '/../../../src/response.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../src/validation/validation.php';
require_once __DIR__ . '/../../../src/auth/session.php';
require_once __DIR__ . '/../../../src/auth/middleware.php';
require_once __DIR__ . '/../../../src/notifications/notification_service.php';

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST')
{
    sendError(405, 'Method Not Allowed');
}
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
    $doctor = null;

    try {
        $pdo->beginTransaction();

        // Lock in current state check inside the transaction to avoid a race
        // between two admins acting on the same doctor at the same time
        $stmt = $pdo->prepare("SELECT user_id, full_name, approval_status FROM doctor_profiles WHERE id = ? FOR UPDATE");
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

    } catch (Exception $e) {
        $pdo->rollBack();
        sendError(500, 'Failed to update doctor status');
    }

    // *** MOVED — notification + response now sit OUTSIDE the
    // transactional try/catch above, ending right at commit(). Nothing
    // that runs after commit() should be inside a block whose catch
    // calls rollBack() — that would throw on an already-committed
    // transaction and mask the real error. Matches how register.php
    // and enquiries.php are structured: notification, then response,
    // both after the transaction's own error handling is done.
    $doctorUserId = (int) $doctor['user_id'];

    // *** ADDED — DOCTOR_REJECTED
    //  Rejection needs the admin's remark embedded, per the 
    // notification matrix ("Your application was rejected" + admin remark)
    // — the remark is functionally required here, not optional.
    try {
        if ($status === 'approved') {
            createNotification(
                $pdo,
                $doctorUserId,
                'doctor',
                'DOCTOR_APPROVED',
                'Doctor application approved',
                'Your application has been approved. You can now provide consultations.',
                'doctor',
                (int) $doctorId
            );
        } else {
            createNotification(
                $pdo,
                $doctorUserId,
                'doctor',
                'DOCTOR_REJECTED',
                'Doctor application rejected',
                "Your application was rejected. Reason: {$remark}",
                'doctor',
                (int) $doctorId
            );
        }
    } catch (Throwable $e) {
        error_log("Failed to create DOCTOR_{$status} notification: " . $e->getMessage());
    }

    sendSuccess([
        'doctor_id'        => $doctorId,
        'approval_status'  => $status,
        'remark'           => $remark,
    ]);