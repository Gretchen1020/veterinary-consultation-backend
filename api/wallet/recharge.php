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
require_once __DIR__ . '/../../src/notifications/notification_service.php';

requireAuth(); // no fixed role — patient or admin both allowed, checked manually below

require_once __DIR__ . '/../../config/db.php'; // provides $pdo 

if ($_SERVER['REQUEST_METHOD'] !== 'POST')
{
    sendError(405, 'Method Not Allowed');
}

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

// ADDED: track the patient's actual users.id for the notification below,
// resolved differently per branch — patient branch already knows it
// from their own session, admin branch needs it looked up.
$patientUserId = null;

if ($role === 'patient') {
    // Patients can only recharge their own wallet — client-supplied
    if ($targetPatientId !== null) {
        sendError(403, 'Cannot recharge another patient\'s wallet');
    }

    $patientId = getPatientProfileId($pdo, (int) $_SESSION['user_id']);

    if ($patientId === null) {
        sendError(404, 'Patient profile not found for this account');
    }

    // Recharging their own wallet — their own session user_id IS the
    // recipient, no lookup needed.
    $patientUserId = (int) $_SESSION['user_id'];
} 
elseif ($role === 'admin') {
    if (!$targetPatientId) {
        sendError(422, 'patient_id is required for admin-initiated recharge');
    } 

$patientId = (int) $targetPatientId;

// Confirm this patient actually exists before creating/crediting a
// // wallet for them
// ADDED: also select user_id — free on this existing existence-check
// query, needed to resolve the notification recipient below since
// only patient_profiles.id (not users.id) is known in this branch.
$stmt = $pdo->prepare('SELECT id, user_id FROM patient_profiles WHERE id = ?');
    $stmt->execute([$patientId]);
    $patientRow = $stmt->fetch();
    if (!$patientRow) {
        sendError(404, 'Patient not found');
    }
    $patientUserId = (int) $patientRow['user_id'];
}
else {
    sendError(403, 'Only patients or admins may recharge a wallet');
}

$wallet = getOrCreateWallet($pdo, $patientId);

try {
    $result = walletCredit($pdo, (int) $wallet['id'], (float) $amount, 'recharge', $reference);

    // *** ADDED — patient notification ***
    // FLAGGED — important: this MUST have its own try/catch, separate
    // from the surrounding one. The surrounding catch (Exception $e)
    // below would otherwise catch a PDOException thrown by
    // createNotification() and report "Recharge failed" to the client
    // — even though walletCredit() already succeeded and the patient's
    // money is already credited. That would be a materially worse bug
    // than a missing notification: telling a patient their recharge
    // failed when it didn't.
    //
    // Only fires on this genuine-credit path, not on the
    // DuplicateReferenceException branch below — a duplicate reference
    // is a repeat of an already-completed, already-notified recharge,
    // not a new event.
    try {
        createNotification(
            $pdo,
            $patientUserId,
            'patient',
            'WALLET_RECHARGED',
            'Wallet recharged',
            sprintf('Wallet successfully recharged with ₹%.2f.', (float) $amount),
            'wallet_transaction',
            (int) $result['transaction_id']
        );
    } catch (Throwable $e) {
        error_log('Failed to create WALLET_RECHARGED notification: ' . $e->getMessage());
    }

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