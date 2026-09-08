<?php
/**
 * BE-18b: Mark Doctor Earning as Paid
 * POST /api/admin/earnings-mark-paid.php  { earning_id }
 *
 * Auth: admin only.
 *
 * DECISION: UPDATEs the existing doctor_earnings row
 * in place (status -> 'paid') rather than appending a separate payout
 * event row. This deliberately breaks from the append-only pattern used
 * elsewhere (wallet_transactions, admin_settings), justified by the
 * Database Design sheet's own description of doctor_earnings as a
 * "ledger/settlement view" with Operations listed as Insert/list/summary -
 * i.e. the spec itself scopes this table more narrowly than the other
 * ledgers. paid_at/paid_by were added via migration specifically so
 * updating in place doesn't lose the who/when audit trail.
 *
 * DECISION: single-row only - no bulk "settle all unpaid earnings for
 * doctor X" action. Not built here to avoid adding unrequested scope;
 * flag to mentor if a batch settlement flow is wanted later.
 *
 * Include depth: api/admin/ -> ../../
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/response.php'; 
require_once __DIR__ . '/../../src/notifications/notification_service.php';

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed.');
}

$input = json_decode(file_get_contents('php://input'), true);

if ($input === null) {
    sendError(400, 'Invalid request');
}

$missingFields = checkRequiredFields($input, ['earning_id']);
if (!empty($missingFields)) {
    sendError(400, 'Missing required field(s): ' . implode(', ', $missingFields));
}

$earningId = filter_var($input['earning_id'], FILTER_VALIDATE_INT);
if (!$earningId) {
    sendError(400, 'earning_id must be a valid integer.');
}

// ADDED: joined doctor_profiles to resolve the actual notification
// recipient — doctor_earnings.doctor_id is doctor_profiles.id (same
// convention as chat_requests.doctor_id, chat_sessions, etc. throughout
// this codebase), NOT users.id, which is what notifications.user_id
// requires. Also pulling amount here since the query is already
// running — lets the notification message state a real figure instead
// of being generic.
$stmt = $pdo->prepare(
    "SELECT de.id, de.doctor_id, de.status, de.amount, dp.user_id AS doctor_user_id
     FROM doctor_earnings de
     JOIN doctor_profiles dp ON dp.id = de.doctor_id
     WHERE de.id = ?"
);
$stmt->execute([$earningId]);
$earning = $stmt->fetch();

if (!$earning) {
    sendError(404, 'Earning record not found.');
}

if ($earning['status'] === 'paid') {
    sendError(409, 'This earning is already marked paid.');
}

$adminUserId = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "UPDATE doctor_earnings
     SET status = 'paid', paid_at = NOW(), paid_by = ?
     WHERE id = ?"
);
$stmt->execute([$adminUserId, $earningId]);

// *** ADDED — doctor notification ***
// No transaction in this file (single UPDATE), so "after the operation
// succeeds" is the same placement rule as request.php/enquiries.php —
// no outer-try risk here. Wrapped defensively: a broken notification
// must never block an admin action that already succeeded.
try {
    createNotification(
        $pdo,
        (int) $earning['doctor_user_id'],
        'doctor',
        'EARNINGS_MARKED_PAID',
        'Earnings marked as paid',
        sprintf('Your earnings payment of ₹%.2f has been marked as paid.', $earning['amount']),
        'doctor_earning',
        $earningId
    );
} catch (Throwable $e) {
    error_log('Failed to create EARNINGS_MARKED_PAID notification: ' . $e->getMessage());
}

sendSuccess([
    'id'        => $earningId,
    'doctor_id' => (int)$earning['doctor_id'],
    'status'    => 'paid',
    'paid_by'   => $adminUserId,
]);