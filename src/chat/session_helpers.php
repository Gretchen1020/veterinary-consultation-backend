<?php
/**
 * Shared chat-session helpers.
 * Used by api/chat/messages.php (BE-15) and api/chat/session.php (BE-16) —
 * both need the same "is this logged-in user a participant on this
 * session" check.
 *
 * BUGFIX (Day 12): the SELECT previously only pulled id, request_id,
 * patient_id, doctor_id, status — enough for messages.php's needs, but
 * session.php and billing_service.php also need started_at,
 * last_heartbeat_at, ended_at, and rate_per_minute on the same row
 * (advanceBilling()/finalizeBilling() read these directly off the
 * array returned here, not via a second query). Added those four
 * columns. This is additive only — messages.php gets the same rows
 * it always did, just with more keys available on each; nothing it
 * currently reads was renamed or removed.
 *
 * BUGFIX (patient-confirmation handshake): same situation again — the
 * new confirmed_at guard in session.php's heartbeat/end branches reads
 * $session['confirmed_at'], which this SELECT didn't include. Without
 * it, empty($session['confirmed_at']) is always true on a missing
 * array key, so the guard would reject every session unconditionally,
 * confirmed or not. Added cs.confirmed_at — additive only, same as
 * the Day 12 fix above.
 *
 * BUGFIX (notifications): messages.php's NEW_CHAT_MESSAGE and
 * billing_service.php's CONSULTATION_ENDED/CONSULTATION_AUTO_ENDED/
 * EARNINGS_GENERATED all resolve their recipient via
 * doctor_user_id/patient_user_id on this row (doctor_id/patient_id
 * elsewhere in this codebase mean doctor_profiles.id/patient_profiles.id,
 * NOT users.id — notifications.user_id requires the latter).
 * doctor_name/patient_name added too, needed for NEW_CHAT_MESSAGE's
 * "New message from X" text. Added via two new JOINs — additive only,
 * same pattern as the fixes above.
 */

require_once __DIR__ . '/../response.php'; 
require_once __DIR__ . '/../auth/patient_profile.php'; // getPatientProfileId()
require_once __DIR__ . '/../auth/doctor_profile.php';  // getDoctorProfileId()

/**
 * Loads the chat_sessions row and confirms the current user is a participant.
 * Returns the session row (array) on success, or calls sendError() and exits.
 *
 * @param bool $requireActive  
 * When true (default), also rejects sessions whose status isn't 'active'. 
 * Callers that need to read/act on ended sessions (e.g. BE-16 session.php reading 
 * a just-ended session for final billing) can pass false to skip that check.
 */
function getAuthorizedSession(PDO $pdo, int $sessionId, int $userId, string $role, bool $requireActive = true): array
{
    $stmt = $pdo->prepare(
    "SELECT
        cs.id,
        cs.request_id,
        cr.patient_id,
        cr.doctor_id,
        cs.status,
        cs.started_at,
        cs.confirmed_at,
        cs.last_heartbeat_at,
        cs.ended_at,
        cs.rate_per_minute,
        dp.user_id AS doctor_user_id,
        dp.full_name AS doctor_name,
        pp.user_id AS patient_user_id,
        pp.full_name AS patient_name
     FROM chat_sessions cs
     INNER JOIN chat_requests cr ON cr.id = cs.request_id
     INNER JOIN doctor_profiles dp ON dp.id = cr.doctor_id
     INNER JOIN patient_profiles pp ON pp.id = cr.patient_id
     WHERE cs.id = ?"
);

    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        sendError(404, 'Chat session not found.');
    }

    // Participant check: map the logged-in user to their own profile id and
    // compare against the session's stored patient_id / doctor_id.
    // T-09: third user joining another consultation room must be denied.
    if ($role === 'patient') {
        $patientProfileId = getPatientProfileId($pdo, $userId);
        if ($patientProfileId === null || (int)$session['patient_id'] !== $patientProfileId) {
            sendError(403, 'You are not a participant in this chat session.');
        }
    } 
    elseif ($role === 'doctor') {
        $doctorProfileId = getDoctorProfileId($pdo, $userId);
        if ($doctorProfileId === null || (int)$session['doctor_id'] !== $doctorProfileId) {
            sendError(403, 'You are not a participant in this chat session.');
        }
    } 
    else {
        // Admin or any other role - not a chat participant by design.
        sendError(403, 'You are not a participant in this chat session.');
    }

    if ($requireActive && $session['status'] !== 'active') {
        sendError(409, 'This chat session is not active.');
    }

    return $session;
}