<?php
/**
 * Day 13 — Admin Earnings Summary (unnumbered, same precedent as the
 * pending.php ?status= addition — not in the original PHP API Contract
 * sheet, added to satisfy the 15-Day Plan's "admin summary endpoints"
 * line).
 *
 * GET /api/admin/earnings-summary.php
 * Auth: Admin only.
 *
 * Query params (optional):
 *   from=YYYY-MM-DD, to=YYYY-MM-DD  -> date range on billing_records.created_at
 *   status=unpaid|paid             -> filter nested earnings AND shrink
 *                                      platform_totals / doctor aggregates to
 *                                      matching doctor_earnings rows only.
 *                                      Omit for full finalized billing view
 *                                      (includes rows with no earnings insert).
 *
 * Response 200:
 *   {
 *     platform_totals: { … },
 *     doctor_breakdown: [
 *       {
 *         doctor_id, doctor_name, session_count,
 *         gross_amount, commission_amount, doctor_amount,
 *         earnings: [ { earnings_id, billing_id, session_id, amount, status, … } ]
 *       }
 *     ]
 *   }
 */

require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../config/db.php';

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method Not Allowed');
}

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$status = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : null;

if ($status !== null && $status !== '' && !in_array($status, ['unpaid', 'paid'], true)) {
    sendError(400, 'status must be unpaid or paid');
}

$filterByEarningsStatus = ($status === 'unpaid' || $status === 'paid');

$where = ["br.billing_status = 'finalized'"];
$params = [];

if ($from !== null) {
    $where[] = 'DATE(br.created_at) >= ?';
    $params[] = $from;
}
if ($to !== null) {
    $where[] = 'DATE(br.created_at) <= ?';
    $params[] = $to;
}

// When status is set, only rows that have a doctor_earnings match with that status.
if ($filterByEarningsStatus) {
    $where[] = 'de.id IS NOT NULL';
    $where[] = 'de.status = ?';
    $params[] = $status;
}

$whereSql = implode(' AND ', $where);

/*
 * Joins used when status filter is active (need doctor_earnings).
 * Without status, totals stay on billing_records only (original behaviour).
 */
$joinSql = '
    INNER JOIN chat_sessions cs ON cs.id = br.session_id
    INNER JOIN chat_requests cr ON cr.id = cs.request_id
    INNER JOIN doctor_profiles d ON d.id = cr.doctor_id
';
if ($filterByEarningsStatus) {
    $joinSql .= ' INNER JOIN doctor_earnings de ON de.billing_id = br.id ';
}

/* Platform-wide totals */
if ($filterByEarningsStatus) {
    $totalsStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(br.gross_amount), 0) AS total_gross_amount,
            COALESCE(SUM(br.commission_amount), 0) AS total_commission_amount,
            COALESCE(SUM(br.doctor_amount), 0) AS total_doctor_amount,
            COUNT(*) AS total_sessions
         FROM billing_records br
         $joinSql
         WHERE $whereSql"
    );
} else {
    $totalsStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(br.gross_amount), 0) AS total_gross_amount,
            COALESCE(SUM(br.commission_amount), 0) AS total_commission_amount,
            COALESCE(SUM(br.doctor_amount), 0) AS total_doctor_amount,
            COUNT(*) AS total_sessions
         FROM billing_records br
         WHERE $whereSql"
    );
}
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

/* Per-doctor aggregates */
$breakdownStmt = $pdo->prepare(
    "SELECT
        d.id AS doctor_id,
        d.full_name AS doctor_name,
        COUNT(*) AS session_count,
        COALESCE(SUM(br.gross_amount), 0) AS gross_amount,
        COALESCE(SUM(br.commission_amount), 0) AS commission_amount,
        COALESCE(SUM(br.doctor_amount), 0) AS doctor_amount
     FROM billing_records br
     $joinSql
     WHERE $whereSql
     GROUP BY d.id, d.full_name
     ORDER BY doctor_amount DESC"
);
$breakdownStmt->execute($params);
$breakdown = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Nested earnings rows — same filters.
 * Without status: LEFT JOIN so missing earnings inserts still appear.
 * With status: INNER JOIN already applied via $joinSql / $whereSql.
 */
if ($filterByEarningsStatus) {
    $rowsStmt = $pdo->prepare(
        "SELECT
            cr.doctor_id,
            de.id AS earnings_id,
            br.id AS billing_id,
            br.session_id,
            de.amount AS amount,
            de.status AS earnings_status,
            de.paid_at,
            de.created_at AS earnings_created_at,
            br.created_at AS billing_created_at
         FROM billing_records br
         $joinSql
         WHERE $whereSql
         ORDER BY cr.doctor_id ASC, br.created_at DESC"
    );
} else {
    $rowsStmt = $pdo->prepare(
        "SELECT
            cr.doctor_id,
            de.id AS earnings_id,
            br.id AS billing_id,
            br.session_id,
            COALESCE(de.amount, br.doctor_amount) AS amount,
            de.status AS earnings_status,
            de.paid_at,
            de.created_at AS earnings_created_at,
            br.created_at AS billing_created_at
         FROM billing_records br
         INNER JOIN chat_sessions cs ON cs.id = br.session_id
         INNER JOIN chat_requests cr ON cr.id = cs.request_id
         LEFT JOIN doctor_earnings de ON de.billing_id = br.id
         WHERE $whereSql
         ORDER BY cr.doctor_id ASC, br.created_at DESC"
    );
}
$rowsStmt->execute($params);
$allRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$earningsByDoctor = [];
foreach ($allRows as $row) {
    $did = (int) $row['doctor_id'];
    if (!isset($earningsByDoctor[$did])) {
        $earningsByDoctor[$did] = [];
    }
    $earningsByDoctor[$did][] = [
        'earnings_id' => $row['earnings_id'] !== null ? (int) $row['earnings_id'] : null,
        'billing_id'  => (int) $row['billing_id'],
        'session_id'  => (int) $row['session_id'],
        'amount'      => (float) $row['amount'],
        'status'      => $row['earnings_status'],
        'paid_at'     => $row['paid_at'],
        'created_at'  => $row['earnings_created_at'] ?? $row['billing_created_at'],
    ];
}

sendSuccess([
    'platform_totals' => [
        'total_gross_amount' => (float) $totals['total_gross_amount'],
        'total_commission_amount' => (float) $totals['total_commission_amount'],
        'total_doctor_amount' => (float) $totals['total_doctor_amount'],
        'total_sessions' => (int) $totals['total_sessions'],
    ],
    'doctor_breakdown' => array_map(static function ($row) use ($earningsByDoctor) {
        $doctorId = (int) $row['doctor_id'];
        return [
            'doctor_id' => $doctorId,
            'doctor_name' => $row['doctor_name'],
            'session_count' => (int) $row['session_count'],
            'gross_amount' => (float) $row['gross_amount'],
            'commission_amount' => (float) $row['commission_amount'],
            'doctor_amount' => (float) $row['doctor_amount'],
            'earnings' => $earningsByDoctor[$doctorId] ?? [],
        ];
    }, $breakdown),
]);
