<?php
/**
 * Resolves patient_profiles.id from a logged-in user's users.id.
 *
 * Several tables (pets.patient_id, wallet_accounts.patient_id, ...)
 * reference patient_profiles.id, NOT users.id directly — patient_profiles
 * has its own auto-increment PK with a user_id FK back to users.id.
 * $_SESSION['user_id'] is always users.id, so anything that needs to
 * look up a patient's OWN rows by patient_id needs this resolution step
 * first.
 *
 * DECISION FLAG: placed in src/auth/ rather than a new src/patient/
 * folder, since it resolves identity (who is this session, as a
 * patient) rather than doing patient-domain work. If patient-specific
 * helpers grow beyond just this, might be worth its own folder later.
 *
 * NOTE: pets.php and profile.php currently do this same lookup inline
 * (as part of a JOIN) rather than through this function — they were
 * written before this was extracted, and haven't been touched here.
 * Worth migrating them to use this too, so there's one source of truth 
 * instead of three slightly different versions of the same query.
 */

function getPatientProfileId(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM patient_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int) $row['id'] : null;
}