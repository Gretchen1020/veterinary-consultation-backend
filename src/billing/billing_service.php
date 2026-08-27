<?php
/**
 * Billing Service (Day 12 — REVISED)
 * -----------------------------------------------------------------
 * Single source of truth for turning elapsed chat time into wallet
 * debits and a finalized billing_records row.
 *
 * REVISION NOTE: billing_records already exists in the DB with a
 * billing_status ENUM('pending','finalized') and an end_reason
 * ENUM('manual','low_balance','auto_timeout'). The row is
 * expected to be INSERTed as 'pending' at session-accept time (see
 * the respond.php patch delivered alongside this file), NOT by this
 * service. finalizeBilling() here only ever UPDATEs an existing
 * 'pending' row to 'finalized' — it no longer INSERTs.
 *
 * This UPDATE ... WHERE billing_status = 'pending' pattern is the
 * T-14 idempotency guard: if rowCount() === 0, someone already
 * finalized it, full stop — no exception-catching needed, MySQL's
 * row locking makes the check-and-flip atomic.
 *
 * Called from three places:
 *   - api/chat/session.php, action=heartbeat, low-balance branch -> end_reason='low_balance'
 *   - api/chat/session.php, action=end                           -> end_reason='manual'
 *   - src/billing/close_stale_sessions.php (cron sweep)           -> end_reason='auto_timeout'
 *
 * DECISION FLAG: locked-in rate vs live admin_settings
 * rate_per_minute is read from chat_sessions.rate_per_minute (set
 * once at accept time), NOT from a fresh admin_settings lookup —
 * a rate change mid-conversation doesn't affect sessions already in
 * progress. commission_percent and minimum_balance ARE read live
 * from admin_settings on every call, since nothing locks those
 * per-session. Flag for mentor: confirm you don't also want
 * commission_percent locked at session-start for the same reasoning.
 *
 * DECISION FLAG: pending row's placeholder columns don't update
 * incrementally. duration_seconds/gross_amount/etc. sit at whatever
 * respond.php inserted (0, presumably) for the whole active session,
 * and only get their real values at finalize time. A client polling
 * GET /api/chat/session.php mid-chat will see billing_status='pending'
 * with zeroed amounts, not a running total. Flag for mentor: fine as
 * a placeholder, or should advanceBilling() also update the pending
 * row every heartbeat so the running total is visible mid-session?
 * That would mean an extra UPDATE per heartbeat for a number nobody
 * currently reads before session end.
 */

require_once __DIR__ . '/../wallet/wallet_service.php';

const BILLING_GRACE_SECONDS = 90;          // ⚠️ MENTOR REVIEW: no spec value given, proposed convention
const HEARTBEAT_INTERVAL_SECONDS = 30;     // ⚠️ MENTOR REVIEW: expected client polling cadence (not enforced server-side)
const LOW_BALANCE_WARNING_SECONDS = 60;    // ⚠️ MENTOR REVIEW: warn when affordable remaining time drops below this

/**
 * Fetch the single active admin_settings row.
 */
function getActiveAdminSettings(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT * FROM admin_settings WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        throw new RuntimeException('No active platform settings configured');
    }

    return $settings;
}

/**
 * Bill elapsed time from the session's last checkpoint up to $upTo.
 * Caps billing at what the wallet can afford down to minimum_balance
 * (T-13) — if the full elapsed window isn't affordable, bills only
 * the affordable partial seconds and reports ended_early=true.
 *
 * Persists the new checkpoint to chat_sessions.last_heartbeat_at
 * before returning, regardless of whether anything was billed.
 *
 * @return array{
 *   seconds_billed: int,
 *   cost: float,
 *   new_checkpoint: DateTime,
 *   ended_early: bool,
 *   duplicate: bool
 * }
 */
function advanceBilling(PDO $pdo, array $session, DateTime $upTo): array
{
    $sessionId = (int) $session['id'];
    $from = $session['last_heartbeat_at']
        ? DateTime::createFromFormat('Y-m-d H:i:s', $session['last_heartbeat_at'])
        : DateTime::createFromFormat('Y-m-d H:i:s', $session['started_at']);

    $elapsedSeconds = $upTo->getTimestamp() - $from->getTimestamp();

    if ($elapsedSeconds <= 0) {
        $stmt = $pdo->prepare("UPDATE chat_sessions SET last_heartbeat_at = ? WHERE id = ?");
        $stmt->execute([$upTo->format('Y-m-d H:i:s'), $sessionId]);

        return [
            'seconds_billed' => 0,
            'cost' => 0.0,
            'new_checkpoint' => $upTo,
            'ended_early' => false,
            'duplicate' => false,
        ];
    }

    $ratePerMinute = (float) $session['rate_per_minute'];
    $ratePerSecond = $ratePerMinute / 60.0;

    $settings = getActiveAdminSettings($pdo);
    $minimumBalance = (float) $settings['minimum_balance'];

    $wallet = getOrCreateWallet($pdo, (int) $session['patient_id']);
    $balance = (float) $wallet['balance'];

    $affordableBalance = $balance - $minimumBalance;
    $affordableSeconds = $affordableBalance > 0 ? (int) floor($affordableBalance / $ratePerSecond) : 0;

    if ($affordableSeconds >= $elapsedSeconds) {
        $secondsToBill = $elapsedSeconds;
        $endedEarly = false;
        $newCheckpoint = $upTo;
    } else {
        $secondsToBill = max(0, $affordableSeconds);
        $endedEarly = true;
        $newCheckpoint = (clone $from)->modify("+{$secondsToBill} seconds");
    }

    $cost = round($secondsToBill * $ratePerSecond, 2);
    $duplicate = false;

    if ($secondsToBill > 0) {
        $referenceId = $sessionId . ':' . $from->format('Y-m-d H:i:s');
        try {
            walletDebit($pdo, (int) $wallet['id'], $cost, 'chat_billing_increment', $referenceId);
        } catch (DuplicateReferenceException $e) {
            $duplicate = true;
        }
    }

    $stmt = $pdo->prepare("UPDATE chat_sessions SET last_heartbeat_at = ? WHERE id = ?");
    $stmt->execute([$newCheckpoint->format('Y-m-d H:i:s'), $sessionId]);

    return [
        'seconds_billed' => $secondsToBill,
        'cost' => $cost,
        'new_checkpoint' => $newCheckpoint,
        'ended_early' => $endedEarly,
        'duplicate' => $duplicate,
    ];
}

/**
 * Finalize a session: bill any remaining time up to $endedAt, flip the
 * existing 'pending' billing_records row to 'finalized' with the given
 * $endReason, and flip chat_sessions to 'ended'. Idempotent (T-14) via
 * the UPDATE ... WHERE billing_status = 'pending' row count check.
 *
 * @param string $endReason One of: 'manual', 'low_balance', 'auto_timeout'
 * @return array{billing_record: array, already_finalized: bool}
 */
function finalizeBilling(PDO $pdo, array $session, DateTime $endedAt, string $endReason): array
{
    $sessionId = (int) $session['id'];

    $stmt = $pdo->prepare("SELECT * FROM billing_records WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && $existing['billing_status'] === 'finalized') {
        return ['billing_record' => $existing, 'already_finalized' => true];
    }

    if (!$existing) {
        // Defensive fallback only — the expected path is that respond.php
        // already inserted a 'pending' row at accept time. This covers
        // sessions created before that patch was applied.
        $insert = $pdo->prepare(
            "INSERT INTO billing_records
                (session_id, duration_seconds, rate_per_minute, gross_amount, commission_amount, doctor_amount, billing_status)
             VALUES (?, 0, ?, 0, 0, 0, 'pending')"
        );
        try {
            $insert->execute([$sessionId, (float) $session['rate_per_minute']]);
        } catch (PDOException $e) {
            // Race: another request just inserted it — fall through to the UPDATE below.
        }
    }

    // BUGFIX: re-fetch last_heartbeat_at fresh from the DB before advancing.
    // The caller's $session array can be stale if it already called
    // advanceBilling() itself earlier in this same request — session.php's
    // heartbeat handler does exactly this before calling finalizeBilling()
    // on the low-balance branch. Without this re-fetch, this internal
    // advanceBilling() call recomputes the SAME elapsed window a second
    // time; the wallet debit is correctly blocked as a duplicate, but the
    // affordability check re-runs against the now-already-debited balance
    // and collapses the checkpoint back to the start of that window —
    // silently zeroing out duration_seconds/gross_amount on the finalized
    // record while the wallet debit itself was already correct.
    $freshStmt = $pdo->prepare("SELECT last_heartbeat_at FROM chat_sessions WHERE id = ?");
    $freshStmt->execute([$sessionId]);
    $session['last_heartbeat_at'] = $freshStmt->fetchColumn();

    // Bill any remaining time. May itself end early (low balance) and
    // return a checkpoint earlier than $endedAt.
    $advance = advanceBilling($pdo, $session, $endedAt);
    $finalCheckpoint = $advance['new_checkpoint'];

    $startedAt = DateTime::createFromFormat('Y-m-d H:i:s', $session['started_at']);
    $durationSeconds = $finalCheckpoint->getTimestamp() - $startedAt->getTimestamp();

    $ratePerMinute = (float) $session['rate_per_minute'];
    $grossAmount = round($durationSeconds * ($ratePerMinute / 60.0), 2);

    $settings = getActiveAdminSettings($pdo);
    $commissionPercent = (float) $settings['commission_percent'];
    $commissionAmount = round($grossAmount * ($commissionPercent / 100.0), 2);
    $doctorAmount = round($grossAmount - $commissionAmount, 2);

    $update = $pdo->prepare(
        "UPDATE billing_records
         SET duration_seconds = ?, gross_amount = ?, commission_amount = ?, doctor_amount = ?,
             billing_status = 'finalized', end_reason = ?
         WHERE session_id = ? AND billing_status = 'pending'"
    );
    $update->execute([$durationSeconds, $grossAmount, $commissionAmount, $doctorAmount, $endReason, $sessionId]);

    if ($update->rowCount() === 0) {
        // Someone else finalized it between our pre-check and now.
        $stmt->execute([$sessionId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['billing_record' => $existing, 'already_finalized' => true];
    }

    $sessionUpdate = $pdo->prepare(
        "UPDATE chat_sessions SET status = 'ended', ended_at = ? WHERE id = ? AND status = 'active'"
    );
    $sessionUpdate->execute([$finalCheckpoint->format('Y-m-d H:i:s'), $sessionId]);

    $stmt->execute([$sessionId]);
    $billingRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    return ['billing_record' => $billingRecord, 'already_finalized' => false];
}

function isLowBalanceWarning(float $balance, float $minimumBalance, float $ratePerMinute): bool
{
    $ratePerSecond = $ratePerMinute / 60.0;
    if ($ratePerSecond <= 0) {
        return false;
    }
    $affordableSeconds = ($balance - $minimumBalance) / $ratePerSecond;
    return $affordableSeconds < LOW_BALANCE_WARNING_SECONDS;
}