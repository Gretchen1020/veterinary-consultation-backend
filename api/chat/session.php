<?php
/**
 * BE-16: GET/POST /api/chat/session.php
 * Auth: Session Participant
 *
 * GET  ?session_id=X                    -> status snapshot (works for active OR ended sessions)
 * POST { session_id, action: 'heartbeat' | 'end' }
 *
 * DECISION FLAG: who can call action=end
 * Spec doesn't say whether ending is patient-only, doctor-only, or
 * either participant. Implemented as: either participant may end.
 * Flag for mentor: confirm, or restrict to one role if that's the
 * intended UX (e.g. only the patient can voluntarily end, since
 * they're the one being billed).
 *
 * DECISION FLAG: action=heartbeat on an already-ended session
 * Returns 409 (via getAuthorizedSession's $requireActive=true) rather
 * than silently succeeding — a heartbeat arriving after the session
 * already ended (e.g. race with the cron sweep) is treated as a
 * client-side signal to stop polling and re-check status via GET.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/chat/session_helpers.php';
require_once __DIR__ . '/../../src/wallet/wallet_service.php';
require_once __DIR__ . '/../../src/billing/billing_service.php';

requireAuth(); // patient or doctor — role checked per-action via getAuthorizedSession

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
    if ($sessionId <= 0) {
        sendError(400, 'session_id is required');
    }

    // requireActive=false: a participant should be able to check status
    // on a just-ended session too (e.g. final billing summary screen).
    $session = getAuthorizedSession($pdo, $sessionId, $userId, $role, false);

    $wallet = getOrCreateWallet($pdo, (int) $session['patient_id']);
    $settings = getActiveAdminSettings($pdo);

    $billingStmt = $pdo->prepare("SELECT * FROM billing_records WHERE session_id = ?");
    $billingStmt->execute([$sessionId]);
    $billingRecord = $billingStmt->fetch(PDO::FETCH_ASSOC);

    $response = [
        'session_id' => $sessionId,
        'status' => $session['status'],
        'started_at' => $session['started_at'],
        'last_heartbeat_at' => $session['last_heartbeat_at'],
        'ended_at' => $session['ended_at'],
        'rate_per_minute' => (float) $session['rate_per_minute'],
        'wallet_balance' => (float) $wallet['balance'],
        'low_balance_warning' => isLowBalanceWarning(
            (float) $wallet['balance'],
            (float) $settings['minimum_balance'],
            (float) $session['rate_per_minute']
        ),
        'billing_record' => $billingRecord ?: null,
    ];

    sendSuccess($response);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $missing = checkRequiredFields($input, ['session_id', 'action']);
    if ($missing) {
        sendError(400, 'Missing required fields: ' . implode(', ', $missing));
    }

    $sessionId = (int) $input['session_id'];
    $action = $input['action'];

    if (!isValidEnum($action, ['heartbeat', 'end'])) {
        sendError(400, 'action must be heartbeat or end');
    }

    if ($action === 'heartbeat') {
        // requireActive=true (default) — a heartbeat only makes sense on an active session.
        $session = getAuthorizedSession($pdo, $sessionId, $userId, $role);

        $now = new DateTime();
        $advance = advanceBilling($pdo, $session, $now);

        if ($advance['ended_early']) {
            // T-13: ran out of affordable balance mid-heartbeat — finalize now.
            $result = finalizeBilling($pdo, $session, $advance['new_checkpoint'], 'low_balance');
            sendSuccess([
                'session_id' => $sessionId,
                'status' => 'ended',
                'reason' => 'low_balance',
                'seconds_billed_this_tick' => $advance['seconds_billed'],
                'billing_record' => $result['billing_record'],
            ]);
        }

        $wallet = getOrCreateWallet($pdo, (int) $session['patient_id']);
        $settings = getActiveAdminSettings($pdo);

        sendSuccess([
            'session_id' => $sessionId,
            'status' => 'active',
            'seconds_billed_this_tick' => $advance['seconds_billed'],
            'cost_this_tick' => $advance['cost'],
            'wallet_balance' => (float) $wallet['balance'],
            'low_balance_warning' => isLowBalanceWarning(
                (float) $wallet['balance'],
                (float) $settings['minimum_balance'],
                (float) $session['rate_per_minute']
            ),
        ]);
    }

    if ($action === 'end') {
        // requireActive=false: allow "end" to be called on a session that
        // the cron sweep (or a race) already closed — finalizeBilling's
        // own idempotency check handles that gracefully (T-14).
        $session = getAuthorizedSession($pdo, $sessionId, $userId, $role, false);

        $now = new DateTime();
        $result = finalizeBilling($pdo, $session, $now, 'manual');

        sendSuccess([
            'session_id' => $sessionId,
            'status' => 'ended',
            'already_finalized' => $result['already_finalized'],
            'billing_record' => $result['billing_record'],
        ]);
    }
}

sendError(405, 'Method not allowed');