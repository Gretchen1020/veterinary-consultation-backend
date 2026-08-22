<?php
/**
 * BE-08: GET /api/wallet/details.php
 * Auth: Patient
 * Returns balance + transaction list for the logged-in patient's wallet.
 */

require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/patient_profile.php';
require_once __DIR__ . '/../../src/wallet/wallet_service.php';

requireAuth('patient');

require_once __DIR__ . '/../../config/db.php'; // provides $pdo

$patientId = getPatientProfileId($pdo, (int) $_SESSION['user_id']);
if ($patientId === null) {
    sendError(404, 'Patient profile not found for this account');
}

$wallet = getOrCreateWallet($pdo, $patientId);

// Pagination: contract just says "balance and transactions" with no
// explicit paging fields, so defaulting to last 20, newest first,
// with optional ?limit= override capped at 100 to avoid abuse.
$limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 20;

$stmt = $pdo->prepare(
    'SELECT id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, created_at
     FROM wallet_transactions
     WHERE wallet_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT ?'
);
$stmt->bindValue(1, $wallet['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

sendSuccess([
    'wallet_id'    => (int) $wallet['id'],
    'balance'      => (float) $wallet['balance'],
    'updated_at'   => $wallet['updated_at'],
    'transactions' => $transactions,
]);