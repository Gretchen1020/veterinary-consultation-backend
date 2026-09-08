<?php
/**
 * Notification service — creation only. Reading/marking-read lives in
 * api/notifications/*.php instead; this file has no knowledge of
 * dashboards, pages, or clicking.
 *
 * DESIGN DECISIONS (confirmed during Day 14 discussion):
 *
 * 1. Multi-admin safe by default: whether this system ever has one
 *    admin or several is currently unconfirmed (flagged for mentor).
 *    Rather than assume single-admin and risk silently missing future
 *    admins, admin-directed events loop over EVERY admin user via
 *    getAllAdminUserIds() and insert one row per admin. Costs nothing
 *    if there's genuinely only one admin — the loop just runs once.
 *
 * 2. Failure isolation: createNotification() calls belong AFTER the
 *    caller's main transaction commits, and should be wrapped in a
 *    try/catch at the call site. A broken notification insert must
 *    never be able to roll back or block a real business action
 *    (a doctor accepting a request, a registration succeeding, etc.).
 *    This file does not enforce that itself — it's a caller
 *    responsibility, documented here so it isn't forgotten.
 */

/**
 * Create a notification for a single user.
 *
 * @return int Notification ID
 */
function createNotification(
    PDO $pdo,
    int $userId,
    string $role,
    string $type,
    string $title,
    string $message,
    ?string $referenceType = null,
    ?int $referenceId = null
): int {
    $stmt = $pdo->prepare(
        "INSERT INTO notifications
            (user_id, role, type, title, message, reference_type, reference_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $userId,
        $role,
        $type,
        $title,
        $message,
        $referenceType,
        $referenceId,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * All current admin user_ids. Used so admin-directed events reach
 * every admin, not just one hardcoded/assumed account — see design
 * decision #1 above.
 *
 * @return int[]
 */
function getAllAdminUserIds(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Convenience wrapper: create the same notification for every current
 * admin. Callers should still wrap this in their own try/catch after
 * their main transaction commits — this function does not swallow
 * errors itself, to keep behavior explicit rather than hiding failures
 * two layers deep.
 */
function notifyAllAdmins(
    PDO $pdo,
    string $type,
    string $title,
    string $message,
    ?string $referenceType = null,
    ?int $referenceId = null
): void {
    foreach (getAllAdminUserIds($pdo) as $adminUserId) {
        createNotification(
            $pdo,
            $adminUserId,
            'admin',
            $type,
            $title,
            $message,
            $referenceType,
            $referenceId
        );
    }
}