<?php
// api/admin/settings.php  (BE-12)
// Path depth: api/admin/ is 2 levels under public/ (one shallower than
// api/admin/doctors/, which needs ../../../) so this file uses ../../
require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/auth/session.php';
require_once __DIR__ . '/../../src/auth/middleware.php';

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt = $pdo->prepare("SELECT * FROM admin_settings WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $active = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM admin_settings ORDER BY created_at DESC");
    $stmt->execute();
    $history = $stmt->fetchAll();

    sendSuccess([
        'active'  => $active ?: null,
        'history' => $history,
    ]);

} 
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        sendError(400, 'Invalid request');
    }

    $missingFields = checkRequiredFields($input, ['rate_per_minute', 'commission_percent', 'minimum_balance', 'currency']);
    if (!empty($missingFields)) {
        sendError(400, 'Invalid request');
    }

    $ratePerMinute = $input['rate_per_minute'];
    $commissionPercent = $input['commission_percent'];
    $minimumBalance = $input['minimum_balance'];
    $currency = $input['currency'];

    // rate_per_minute must be strictly positive — isValidAmount() already enforces > 0
    if (!isValidAmount($ratePerMinute)) {
        sendError(400, 'Invalid rate_per_minute');
    }

    // commission_percent: 0 is legitimate (platform could run commission-free), so
    // isValidAmount() (which requires > 0) doesn't fit — inline range check instead
    if (!is_numeric($commissionPercent) || (float)$commissionPercent < 0 || (float)$commissionPercent > 100) {
        sendError(400, 'Invalid commission_percent');
    }

    // minimum_balance: 0 is a legitimate floor, same reasoning as above
    if (!is_numeric($minimumBalance) || (float)$minimumBalance < 0) {
        sendError(400, 'Invalid minimum_balance');
    }

    // NOTE: allowed currency list — adjust to whatever set you actually support.
    // Flagging this as a placeholder rather than guessing your real business list.
    $allowedCurrencies = ['INR', 'USD'];
    if (!isValidEnum($currency, $allowedCurrencies)) {
        sendError(400, 'Invalid currency');
    }

    $adminId = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();

        // Deactivate whatever is currently active — versioned insert, never UPDATE values
        $stmt = $pdo->prepare("UPDATE admin_settings SET is_active = 0 WHERE is_active = 1");
        $stmt->execute();

        $stmt = $pdo->prepare("INSERT INTO admin_settings
            (rate_per_minute, commission_percent, minimum_balance, currency, is_active, created_by, created_at)
            VALUES (?, ?, ?, ?, 1, ?, NOW())");
        $stmt->execute([$ratePerMinute, $commissionPercent, $minimumBalance, $currency, $adminId]);

        $newId = $pdo->lastInsertId();

        $pdo->commit();

        sendSuccess([
            'id'                 => $newId,
            'rate_per_minute'    => $ratePerMinute,
            'commission_percent' => $commissionPercent,
            'minimum_balance'    => $minimumBalance,
            'currency'           => $currency,
            'is_active'          => 1,
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        sendError(500, 'Failed to update settings');
    }

} else {
    sendError(405, 'Method Not Allowed');
}