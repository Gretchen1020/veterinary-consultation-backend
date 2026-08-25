<?php
// Mirrors src/auth/patient_profile.php exactly — same identity-resolution pattern.

function getDoctorProfileId(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM doctor_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}