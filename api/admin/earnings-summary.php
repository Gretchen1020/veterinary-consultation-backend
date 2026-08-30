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
 *   from=YYYY-MM-DD, to=YYYY-MM-DD  -> date range applied to BOTH the
 *                                      platform totals and the per-doctor
 *                                      breakdown, filtered on
 *                                      billing_records.created_at (the
 *                                      finalize timestamp — i.e. when the
 *                                      money was actually settled, not
 *                                      when the session started).
 *
 * Response 200:
 *   {
 *     platform_totals: {
 *       total_gross_amount, total_commission_amount, total_doctor_amount,
 *       total_sessions
 *     },
 *     doctor_breakdown: [
 *       { doctor_id, doctor_name, session_count,
 *         gross_amount, commission_amount, doctor_amount }
 *     ]
 *   }
 *
 * DECISION: sourced from billing_records (gross/commission/doctor split
 * per finalized session), not doctor_earnings. billing_records is the
 * platform-wide source of truth including the commission leg, which
 * doctor_earnings deliberately doesn't carry (it's doctor-facing only —
 * doctor_id, amount, status). Only 'finalized' rows are counted; a
 * 'pending' row mid-session hasn't actually been collected yet.
 */

require_once __DIR__ . '/../../src/response.php';
require_once __DIR__ . '/../../src/auth/middleware.php';

requireAuth('admin');

require_once __DIR__ . '/../../config/db.php'; // provides $pdo

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

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
$whereSql = implode(' AND ', $where);

/* Platform-wide totals across all finalized sessions in range. */
$totalsStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(br.gross_amount), 0) AS total_gross_amount,
        COALESCE(SUM(br.commission_amount), 0) AS total_commission_amount,
        COALESCE(SUM(br.doctor_amount), 0) AS total_doctor_amount,
        COUNT(*) AS total_sessions
     FROM billing_records br
     WHERE $whereSql"
);
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

/*
 * Per-doctor breakdown. Joins billing_records -> chat_sessions ->
 * chat_requests to reach doctor_id (billing_records itself only knows
 * session_id, per the Day 12 schema), then to doctors for the display
 * name. doctors.full_name is used directly — no join to users needed
 * for naming, since users only carries auth fields (email, pin_hash,
 * role) and has no name column at all.
 */
$breakdownStmt = $pdo->prepare(
    "SELECT
        d.id AS doctor_id,
        d.full_name AS doctor_name,
        COUNT(*) AS session_count,
        COALESCE(SUM(br.gross_amount), 0) AS gross_amount,
        COALESCE(SUM(br.commission_amount), 0) AS commission_amount,
        COALESCE(SUM(br.doctor_amount), 0) AS doctor_amount
     FROM billing_records br
     INNER JOIN chat_sessions cs ON cs.id = br.session_id
     INNER JOIN chat_requests cr ON cr.id = cs.request_id
     INNER JOIN doctor_profiles d ON d.id = cr.doctor_id
     WHERE $whereSql
     GROUP BY d.id, d.full_name
     ORDER BY doctor_amount DESC"
);
$breakdownStmt->execute($params);
$breakdown = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

sendSuccess([
    'platform_totals' => [
        'total_gross_amount' => (float) $totals['total_gross_amount'],
        'total_commission_amount' => (float) $totals['total_commission_amount'],
        'total_doctor_amount' => (float) $totals['total_doctor_amount'],
        'total_sessions' => (int) $totals['total_sessions'],
    ],
    'doctor_breakdown' => array_map(static function ($row) {
        return [
            'doctor_id' => (int) $row['doctor_id'],
            'doctor_name' => $row['doctor_name'],
            'session_count' => (int) $row['session_count'],
            'gross_amount' => (float) $row['gross_amount'],
            'commission_amount' => (float) $row['commission_amount'],
            'doctor_amount' => (float) $row['doctor_amount'],
        ];
    }, $breakdown),
]);