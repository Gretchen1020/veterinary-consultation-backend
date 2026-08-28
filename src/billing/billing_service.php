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
 * DECISION FLAG: locked-in rate AND commission vs live admin_settings
 * rate_per_minute is read from chat_sessions.rate_per_minute (set once
 * at accept time). commission_percent is ALSO locked as of this
 * revision — read via chat_sessions.admin_settings_id, a reference to
 * whichever admin_settings row was active at accept time, not a fresh
 * live lookup. minimum_balance remains LIVE (read fresh from the
 * currently-active admin_settings row on every billing call) — treated
 * as a real-time risk/protection policy rather than a per-session price
 * term, so a platform-wide change to it should apply immediately, even
 * to sessions already in progress.
 *
 * The pending billing_records row now DOES update incrementally: every
 * live heartbeat (touchHeartbeat=true) recomputes duration_seconds/
 * gross_amount from started_at and writes them to the 'pending' row, so
 * a client polling GET mid-chat sees a real running total instead of
 * zeros. commission_amount/doctor_amount are deliberately left at 0
 * until real finalize time, since they depend on the locked
 * commission_percent lookup, which only finalizeBilling() performs.
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
 * When $touchHeartbeat is true (a genuine live heartbeat), persists the
 * new checkpoint to chat_sessions.last_heartbeat_at before returning.
 * When false (called internally by finalizeBilling()), the checkpoint
 * is computed and returned but NOT persisted here — the caller is
 * responsible for writing it to the correct column (ended_at).
 *
 * @return array{
 *   seconds_billed: int,
 *   cost: float,
 *   new_checkpoint: DateTime,
 *   ended_early: bool,
 *   duplicate: bool
 * }
 * @param bool $touchHeartbeat  When true (default), persists the computed
 *                              checkpoint to chat_sessions.last_heartbeat_at —
 *                              appropriate ONLY for a genuine live heartbeat
 *                              request. finalizeBilling()'s internal call
 *                              passes false, since that call isn't a real
 *                              ping and must not overwrite the true last-ping
 *                              timestamp the grace-window sweep depends on.
 */
function advanceBilling(PDO $pdo, array $session, DateTime $upTo, bool $touchHeartbeat = true): array
{
    $sessionId = (int) $session['id'];
    $from = $session['last_heartbeat_at']
        ? DateTime::createFromFormat('Y-m-d H:i:s', $session['last_heartbeat_at'])
        : DateTime::createFromFormat('Y-m-d H:i:s', $session['started_at']);

    $elapsedSeconds = $upTo->getTimestamp() - $from->getTimestamp();

    if ($elapsedSeconds <= 0) {
        if ($touchHeartbeat) {
            $stmt = $pdo->prepare("UPDATE chat_sessions SET last_heartbeat_at = ? WHERE id = ?");
            $stmt->execute([$upTo->format('Y-m-d H:i:s'), $sessionId]);
        }

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

    if ($touchHeartbeat) {
        $stmt = $pdo->prepare("UPDATE chat_sessions SET last_heartbeat_at = ? WHERE id = ?");
        $stmt->execute([$newCheckpoint->format('Y-m-d H:i:s'), $sessionId]);

        // Live running-total update to the 'pending' billing_records row,
        // so a client polling GET mid-chat sees real numbers instead of
        // zeros. Recomputed as an absolute span from started_at each time
        // (not accumulated tick-by-tick), so no drift is possible regardless
        // of how many heartbeats have fired. Only duration_seconds/gross_amount
        // — commission_amount/doctor_amount are intentionally left alone here;
        // those only get computed once, at real finalize time, using the
        // commission_percent locked in at accept time (see finalizeBilling()).
        $startedAt = DateTime::createFromFormat('Y-m-d H:i:s', $session['started_at']);
        $liveDurationSeconds = $newCheckpoint->getTimestamp() - $startedAt->getTimestamp();
        $liveGrossAmount = round($liveDurationSeconds * $ratePerSecond, 2);

        $liveUpdate = $pdo->prepare(
            "UPDATE billing_records
             SET duration_seconds = ?, gross_amount = ?
             WHERE session_id = ? AND billing_status = 'pending'"
        );
        $liveUpdate->execute([$liveDurationSeconds, $liveGrossAmount, $sessionId]);
    }

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

    // BUGFIX: re-fetch last_heartbeat_at fresh from the DB before advancing,
    // so this internal call's "from" checkpoint reflects reality even if a
    // live heartbeat call already advanced it earlier in this same request.
    $freshStmt = $pdo->prepare("SELECT last_heartbeat_at FROM chat_sessions WHERE id = ?");
    $freshStmt->execute([$sessionId]);
    $session['last_heartbeat_at'] = $freshStmt->fetchColumn();

    // Bill any remaining time. May itself end early (low balance) and
    // return a checkpoint earlier than $endedAt. touchHeartbeat=false:
    // this call is NOT a real incoming ping (it's a manual end, a
    // low-balance auto-end, or the cron sweep), so it must not overwrite
    // last_heartbeat_at — only a genuine heartbeat request is allowed to
    // do that. The computed checkpoint is instead written to ended_at
    // below, which is where a finalize-time result belongs.
    $advance = advanceBilling($pdo, $session, $endedAt, false);
    $finalCheckpoint = $advance['new_checkpoint'];

    $startedAt = DateTime::createFromFormat('Y-m-d H:i:s', $session['started_at']);
    $durationSeconds = $finalCheckpoint->getTimestamp() - $startedAt->getTimestamp();

    $ratePerMinute = (float) $session['rate_per_minute'];
    $grossAmount = round($durationSeconds * ($ratePerMinute / 60.0), 2);

    // Locked commission_percent: looked up via chat_sessions.admin_settings_id
    // (the admin_settings row that was active at accept time), NOT via a
    // live getActiveAdminSettings() call — a rate/commission change while
    // this session was in progress must not retroactively change the split
    // on a session that already started under the old terms. Falls back to
    // a live lookup only for sessions created before admin_settings_id
    // existed (NULL on old rows).
    $lockedStmt = $pdo->prepare(
        "SELECT ast.commission_percent
         FROM chat_sessions cs
         LEFT JOIN admin_settings ast ON ast.id = cs.admin_settings_id
         WHERE cs.id = ?"
    );
    $lockedStmt->execute([$sessionId]);
    $commissionPercent = $lockedStmt->fetchColumn();

    if ($commissionPercent === false || $commissionPercent === null) {
        // Defensive fallback: session predates the admin_settings_id column.
        $fallbackSettings = getActiveAdminSettings($pdo);
        $commissionPercent = $fallbackSettings['commission_percent'];
    }
    $commissionPercent = (float) $commissionPercent;

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