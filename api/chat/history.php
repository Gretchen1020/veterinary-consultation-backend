<?php
/**
 * BE-17: Chat History
 * GET /api/chat/history.php?page=X&limit=Y[&status=ended]
 *   -> paginated list of the logged-in user's past chat sessions
 * GET /api/chat/history.php?session_id=X&page=Y&limit=Z
 *   -> paginated message transcript for one specific past session
 *
 * Auth: any logged-in patient or doctor. List mode is scoped to the
 * caller's own sessions via their profile id; transcript mode is
 * authorized per-session via getAuthorizedSession(requireActive: false).
 *
 * DECISION: the API contract only says "pagination/filter, authorized
 * session/message history" - no split between list vs transcript is
 * specified. Splitting on presence of ?session_id= was chosen because
 * messages.php's GET already refuses ended sessions (requireActive
 * defaults true), so this is currently the only way to read a
 * transcript after a session has ended. Flag to mentor if a different
 * shape was intended.
 *
 * Include depth: api/chat/ -> ../../
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/response.php'; // sendSuccess() / sendError()
require_once __DIR__ . '/../../src/chat/session_helpers.php'; // getAuthorizedSession()
require_once __DIR__ . '/../../src/auth/patient_profile.php'; // getPatientProfileId()
require_once __DIR__ . '/../../src/auth/doctor_profile.php';  // getDoctorProfileId()

requireAuth();

$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed.');
}

// DECISION: pagination caps, matching the MESSAGE_PAGE_SIZE precedent set
// in messages.php (Day 11) - default page size 10, hard cap 50 so a client
// can't request an unbounded result set.
const HISTORY_DEFAULT_LIMIT = 10;
const HISTORY_MAX_LIMIT = 50;

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
if (!$page || $page < 1) {
    $page = 1;
}

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
if (!$limit || $limit < 1) {
    $limit = HISTORY_DEFAULT_LIMIT;
}
if ($limit > HISTORY_MAX_LIMIT) {
    $limit = HISTORY_MAX_LIMIT;
}
$offset = ($page - 1) * $limit;

$sessionId = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);

if ($sessionId) {
    // ---- TRANSCRIPT MODE: full message history for one past session ----
    // requireActive: false - this is the whole reason that flag exists.
    getAuthorizedSession($pdo, $sessionId, $userId, $role, false);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ?");
    $countStmt->execute([$sessionId]);
    $totalMessages = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, session_id, sender_id, message_text, sent_at
         FROM chat_messages
         WHERE session_id = ?
         ORDER BY id ASC
         LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $sessionId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll();

    sendSuccess([
        'session_id' => $sessionId,
        'page'       => $page,
        'limit'      => $limit,
        'total'      => $totalMessages,
        'messages'   => $messages,
    ]);
}
else {
    // ---- LIST MODE: past sessions for the logged-in user ----
    if ($role === 'patient') {
        $profileId = getPatientProfileId($pdo, $userId);
        if ($profileId === null) {
            sendError(403, 'Patient profile not found.');
        }
        $participantColumn = 'cr.patient_id';
    }
    elseif ($role === 'doctor') {
        $profileId = getDoctorProfileId($pdo, $userId);
        if ($profileId === null) {
            sendError(403, 'Doctor profile not found.');
        }
        $participantColumn = 'cr.doctor_id';
    }
    else {
        // Admin or any other role - history is a patient/doctor concept.
        sendError(403, 'This endpoint is only available to patients and doctors.');
    }

    // DECISION: only 'ended' sessions counted as "history" by default.
    // Contract mentions "filter" without specifying which field - allowing
    // an explicit ?status= override in case a mentor-confirmed use case
    // wants active sessions listed here too, but defaulting to ended so
    // an in-progress consult doesn't show up as "history" mid-chat.
    $status = $_GET['status'] ?? 'ended';
    $allowedStatuses = ['ended', 'active'];
    if (!in_array($status, $allowedStatuses, true)) {
        sendError(400, 'Invalid status filter.');
    }

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM chat_sessions cs
         INNER JOIN chat_requests cr ON cr.id = cs.request_id
         WHERE {$participantColumn} = ? AND cs.status = ?"
    );
    $countStmt->execute([$profileId, $status]);
    $totalSessions = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            cs.id AS session_id,
            cs.status,
            cs.started_at,
            cs.ended_at,
            cs.rate_per_minute,
            cr.pet_id,
            p.name AS pet_name,
            dp.id AS doctor_id,
            dp.full_name AS doctor_name,
            pp.id AS patient_id,
            pp.full_name AS patient_name,
            br.duration_seconds,
            br.gross_amount,
            br.doctor_amount,
            br.billing_status,
            br.end_reason
         FROM chat_sessions cs
         INNER JOIN chat_requests cr ON cr.id = cs.request_id
         INNER JOIN pets p ON p.id = cr.pet_id
         INNER JOIN doctor_profiles dp ON dp.id = cr.doctor_id
         INNER JOIN patient_profiles pp ON pp.id = cr.patient_id
         LEFT JOIN billing_records br ON br.session_id = cs.id
         WHERE {$participantColumn} = ? AND cs.status = ?
         ORDER BY cs.ended_at DESC, cs.id DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $profileId, PDO::PARAM_INT);
    $stmt->bindValue(2, $status, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $sessions = $stmt->fetchAll();

    sendSuccess([
        'page'     => $page,
        'limit'    => $limit,
        'total'    => $totalSessions,
        'sessions' => $sessions,
    ]);
}