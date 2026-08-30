<?php
/**
 * BE-18 — Doctor Earnings & Reports
 * GET /api/doctors/earnings.php
 *
 * Auth: logged-in Doctor only (own earnings only — doctor_id is derived
 * server-side from $_SESSION['user_id'], never taken from a query param,
 * per the project's ownership-authorization pattern).
 *
 * Query params (all optional):
 *   from=YYYY-MM-DD, to=YYYY-MM-DD  -> filters the ledger list only.
 *                                      today_earning / total_earning are
 *                                      always computed independent of
 *                                      these filters, so T-15 has a
 *                                      stable figure to reconcile against
 *                                      regardless of what the doctor is
 *                                      currently browsing.
 *   page, per_page                  -> ledger pagination (default 1 / 20,
 *                                      per_page capped at 100)
 *
 * Response 200:
 *   {
 *     doctor_id, today_earning, total_earning,
 *     ledger: [
 *       { id, billing_id, amount, status, created_at,
 *         session_id, duration_seconds, gross_amount, commission_amount }
 *     ],
 *     pagination: { page, per_page, total }
 *   }
 */

require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/doctor_profile.php'; // getDoctorProfileId()
requireAuth('doctor');

require_once __DIR__ . '/../../config/db.php'; // provides $pdo — matches wallet/details.php's require order (after requireAuth)

$doctorId = getDoctorProfileId($pdo, $_SESSION['user_id']);
if ($doctorId === null) {
    sendError(403, 'Doctor profile not found.');
}

/*
 * Today's / total earning: summed straight off doctor_earnings, not
 * billing_records. doctor_earnings is the doctor-facing ledger and the
 * thing T-15 reconciles against, so these totals should reflect exactly
 * what that table holds, independent of the ledger list's date filter
 * below.
 */
$todayStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS today_earning
     FROM doctor_earnings
     WHERE doctor_id = ? AND DATE(created_at) = CURDATE()"
);
$todayStmt->execute([$doctorId]);
$todayEarning = (float) $todayStmt->fetchColumn();

$totalStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total_earning
     FROM doctor_earnings
     WHERE doctor_id = ?"
);
$totalStmt->execute([$doctorId]);
$totalEarning = (float) $totalStmt->fetchColumn();

/*
 * Ledger list, optionally date-filtered, joined back to billing_records
 * so the doctor can see the underlying session breakdown (duration,
 * gross amount, commission) behind each earnings row, not just the
 * final amount.
 */
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$where = ['de.doctor_id = ?'];
$params = [$doctorId];

if ($from !== null) {
    $where[] = 'DATE(de.created_at) >= ?';
    $params[] = $from;
}
if ($to !== null) {
    $where[] = 'DATE(de.created_at) <= ?';
    $params[] = $to;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM doctor_earnings de WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// $perPage / $offset are cast to int above (never raw user input), so
// safe to interpolate directly — PDO placeholders for LIMIT/OFFSET are
// unreliable across drivers.
$ledgerStmt = $pdo->prepare(
    "SELECT
        de.id, de.billing_id, de.amount, de.status, de.created_at,
        br.session_id, br.duration_seconds, br.gross_amount, br.commission_amount
     FROM doctor_earnings de
     INNER JOIN billing_records br ON br.id = de.billing_id
     WHERE $whereSql
     ORDER BY de.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$ledgerStmt->execute($params);
$ledger = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

sendSuccess([
    'doctor_id' => $doctorId,
    'today_earning' => $todayEarning,
    'total_earning' => $totalEarning,
    'ledger' => $ledger,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
    ],
]);