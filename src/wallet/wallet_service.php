<?php
/**
 * Wallet Service
 * -----------------------------------------------------------------
 * Single source of truth for wallet balance mutation. Both recharge.php
 * (Day 8) and the billing engine (Day 12) MUST call walletCredit() /
 * walletDebit() rather than touching wallet_accounts.balance directly.
 * That's the whole point of extracting this now instead of later.
 *
 * DECISION FLAG: wallet auto-provisioning
 * getOrCreateWallet() lazily creates a wallet_accounts row with
 * balance=0 the first time a patient touches the wallet, instead of
 * creating one at registration time (Day 4). Rationale: keeps Day 4
 * patient registration untouched (spec-scoped) and treats "no wallet
 * row yet" as equivalent to "balance 0" rather than an error state.
 * Flag for mentor: confirm you'd rather NOT create wallet rows at
 * patient registration time instead. If you do, replace this with a
 * simple existence check.
 *
 * DECISION FLAG: idempotency key
 * Duplicate-recharge protection (T-07) is enforced by checking for an
 * existing wallet_transactions row with the same
 * (reference_type, reference_id) BEFORE inserting a new one, inside
 * the same locked transaction. This assumes reference_id is unique
 * per payment attempt (e.g. a gateway transaction ID, or a client-
 * generated UUID for test mode). If two different patients could
 * legitimately submit the same reference string, this scheme breaks —
 * confirm reference_id is caller-unique.
 */

/**
 * This file does NOT require config/db.php — every function here takes
 * $pdo as a parameter from the caller instead of opening its own
 * connection. Endpoint files (details.php, recharge.php) are responsible
 * for requiring config/db.php themselves before calling into this file.
 */

class InsufficientBalanceException extends Exception {}
class DuplicateReferenceException extends Exception {
    public array $existingTransaction;
    public function __construct(array $existingTransaction) {
        parent::__construct('Duplicate reference');
        $this->existingTransaction = $existingTransaction;
    }
}

/**
 * Get the wallet row for a patient, creating one (balance 0) if it
 * doesn't exist yet. Not itself locked — callers that need to mutate
 * balance should re-select FOR UPDATE inside their own transaction.
 */
function getOrCreateWallet(PDO $pdo, int $patientId): array
{
    $stmt = $pdo->prepare('SELECT * FROM wallet_accounts WHERE patient_id = ?');
    $stmt->execute([$patientId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($wallet) {
        return $wallet;
    }

    // Not present yet — create it. Race note: if two requests hit this
    // simultaneously for the same brand-new patient, one INSERT wins
    // and the other should catch the duplicate-key error and re-select.
    try {
        $insert = $pdo->prepare('INSERT INTO wallet_accounts (patient_id, balance, updated_at) VALUES (?, 0, NOW())');
        $insert->execute([$patientId]);
    } catch (PDOException $e) {
        // Likely a unique constraint on patient_id from a concurrent create — fall through to re-select.
    }

    $stmt->execute([$patientId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Credit a wallet atomically and append a ledger row.
 * Throws DuplicateReferenceException if this (reference_type, reference_id)
 * has already been applied — caller should treat that as a success
 * (idempotent) response, not a hard error.
 */
function walletCredit(PDO $pdo, int $walletId, float $amount, string $referenceType, string $referenceId): array
{
    if ($amount <= 0) {
        throw new InvalidArgumentException('Credit amount must be positive');
    }

    $pdo->beginTransaction();
    try {
        // Duplicate check happens INSIDE the transaction, before the lock,
        // so a concurrent duplicate submission still gets caught by the
        // unique index recommended below as a belt-and-suspenders guard.
        $dupCheck = $pdo->prepare(
            'SELECT * FROM wallet_transactions WHERE reference_type = ? AND reference_id = ? LIMIT 1'
        );
        $dupCheck->execute([$referenceType, $referenceId]);
        $existing = $dupCheck->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->rollBack();
            throw new DuplicateReferenceException($existing);
        }

        // Row lock so a concurrent debit/credit on the same wallet can't
        // read a stale balance (same pattern as the Day 6 approval race fix).
        $lock = $pdo->prepare('SELECT balance FROM wallet_accounts WHERE id = ? FOR UPDATE');
        $lock->execute([$walletId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            throw new RuntimeException('Wallet not found: ' . $walletId);
        }

        $balanceBefore = (float) $row['balance'];
        $balanceAfter = $balanceBefore + $amount;

        $update = $pdo->prepare('UPDATE wallet_accounts SET balance = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$balanceAfter, $walletId]);

        $insert = $pdo->prepare(
            'INSERT INTO wallet_transactions
                (wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, created_at)
             VALUES (?, "credit", ?, ?, ?, ?, ?, NOW())'
        );
        $insert->execute([$walletId, $amount, $balanceBefore, $balanceAfter, $referenceType, $referenceId]);
        $transactionId = (int) $pdo->lastInsertId();

        $pdo->commit();

        return [
            'transaction_id' => $transactionId,
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
        ];
    } catch (DuplicateReferenceException $e) {
        throw $e; // already rolled back above
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Debit a wallet atomically. Not called by anything on Day 8 — this
 * exists now so Day 12 billing doesn't need a second locking scheme.
 * Throws InsufficientBalanceException if amount > current balance
 * (this is the T-06 guarantee: balance must never go negative).
 */
function walletDebit(PDO $pdo, int $walletId, float $amount, string $referenceType, string $referenceId): array
{
    if ($amount <= 0) {
        throw new InvalidArgumentException('Debit amount must be positive');
    }

    $pdo->beginTransaction();
    try {
        $dupCheck = $pdo->prepare(
            'SELECT * FROM wallet_transactions WHERE reference_type = ? AND reference_id = ? LIMIT 1'
        );
        $dupCheck->execute([$referenceType, $referenceId]);
        $existing = $dupCheck->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->rollBack();
            throw new DuplicateReferenceException($existing);
        }

        $lock = $pdo->prepare('SELECT balance FROM wallet_accounts WHERE id = ? FOR UPDATE');
        $lock->execute([$walletId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            throw new RuntimeException('Wallet not found: ' . $walletId);
        }

        $balanceBefore = (float) $row['balance'];
        if ($balanceBefore < $amount) {
            $pdo->rollBack();
            throw new InsufficientBalanceException('Balance ' . $balanceBefore . ' < requested debit ' . $amount);
        }
        $balanceAfter = $balanceBefore - $amount;

        $update = $pdo->prepare('UPDATE wallet_accounts SET balance = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$balanceAfter, $walletId]);

        $insert = $pdo->prepare(
            'INSERT INTO wallet_transactions
                (wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, created_at)
             VALUES (?, "debit", ?, ?, ?, ?, ?, NOW())'
        );
        $insert->execute([$walletId, $amount, $balanceBefore, $balanceAfter, $referenceType, $referenceId]);
        $transactionId = (int) $pdo->lastInsertId();

        $pdo->commit();

        return [
            'transaction_id' => $transactionId,
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
        ];
    } catch (InsufficientBalanceException | DuplicateReferenceException $e) {
        throw $e; // already rolled back above
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}