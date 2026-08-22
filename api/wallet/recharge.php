<?php
/**
 * BE-09: POST /api/wallet/recharge.php
 * Auth: Patient / Admin / Test
 * Body: { amount, mode, reference, patient_id? }
 *
 * DECISION FLAG: "Test" audience in the contract
 * Role enum (per Day 3 middleware) is patient/doctor/admin — there
 * is no literal "Test" role. Read the contract's "Patient/Admin/Test"
 * as: a patient can recharge their own wallet, an admin can recharge
 * ANY patient's wallet (support/manual top-up), and "Test" just means
 * mode="test" is an accepted value of `mode` for local/dev recharges
 * with no real payment gateway behind it — not a third auth role.
 * Flag for mentor: confirm this reading, or if "Test" was meant to be
 * a genuinely separate bypass (e.g. no-auth endpoint for seed scripts).
 *
 * NOTE: amount and mode now validated through isValidAmount() and
 * isValidEnum() in validation.php (added this session) — checkRequiredFields() still covers presence.
 */

require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/patient_profile.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/wallet/wallet_service.php';

requireAuth(); // no fixed role — patient or admin both allowed, checked manually below

require_once __DIR__ . '/../../config/db.php'; // provides $pdo 

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    sendError(400, 'Invalid JSON body');
    exit;
}

$missing = checkRequiredFields($body, ['amount', 'mode', 'reference']);
if (!empty($missing)) {
    sendError(422, 'Missing required field(s): ' . implode(', ', $missing));
    exit;
}

$amount = $body['amount'];
$mode = trim($body['mode']);
$reference = trim($body['reference']);
$targetPatientId = $body['patient_id'] ?? null;

// --- Validation ---
if (!isValidAmount($amount)) {
    sendError(422, 'amount must be a positive number');
    exit;
}

$allowedModes = ['upi', 'card', 'netbanking', 'test']; // DECISION FLAG: adjust to real allowed modes

if (!isValidEnum($mode, $allowedModes)) {
    sendError(422, 'mode must be one of: ' . implode(', ', $allowedModes));
    exit;
}
if ($reference === '') {
    sendError(422, 'reference is required');
    exit;
}

// --- Determine target wallet owner ---
$role = $_SESSION['role'] ?? null; 

if ($role === 'patient') {
    // Patients can only recharge their own wallet — client-supplied
    if ($targetPatientId !== null) {
        sendError(403, 'Cannot recharge another patient\'s wallet');
    }

    $patientId = getPatientProfileId($pdo, (int) $_SESSION['user_id']);

    if ($patientId === null) {
        sendError(404, 'Patient profile not found for this account');
    }
} 
elseif ($role === 'admin') {
    if (!$targetPatientId) {
        sendError(422, 'patient_id is required for admin-initiated recharge');
    } 

$patientId = (int) $targetPatientId;

// Confirm this patient actually exists before creating/crediting a
// // wallet for them
$stmt = $pdo->prepare('SELECT id FROM patient_profiles WHERE id = ?');
    $stmt->execute([$patientId]);
    if (!$stmt->fetch()) {
        sendError(404, 'Patient not found');
    }
}
else {
    sendError(403, 'Only patients or admins may recharge a wallet');
}

$wallet = getOrCreateWallet($pdo, $patientId);

try {
    $result = walletCredit($pdo, (int) $wallet['id'], (float) $amount, 'recharge', $reference);

    sendSuccess([
        'wallet_id'       => (int) $wallet['id'],
        'transaction_id'  => $result['transaction_id'],
        'balance_before'  => $result['balance_before'],
        'balance_after'   => $result['balance_after'],
        'duplicate'       => false,
    ]);
} catch (DuplicateReferenceException $e) {
    // T-07: duplicate reference -> idempotent success, not an error.
    // Return the ORIGINAL transaction's effect, not a new one.
    $existing = $e->existingTransaction;
    sendSuccess([
        'wallet_id'       => (int) $wallet['id'],
        'transaction_id'  => (int) $existing['id'],
        'balance_before'  => (float) $existing['balance_before'],
        'balance_after'   => (float) $existing['balance_after'],
        'duplicate'       => true,
    ]);
} catch (Exception $e) {
    sendError(500, 'Recharge failed: ' . $e->getMessage());
}