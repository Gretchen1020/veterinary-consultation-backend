<?php
/**
 * BE-15: Chat Messages
 * GET  /api/chat/messages?session_id=X&last_message_id=Y  -> poll new messages
 * POST /api/chat/messages  { session_id, message }         -> send a message
 *
 * Auth: any logged-in patient or doctor who is a participant on the session.
 * Include depth: api/chat/ -> ../../
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/response.php'; // sendSuccess() / sendError()
require_once __DIR__ . '/../../src/chat/session_helpers.php'; // getAuthorizedSession()

// DECISION: no specific role required at the auth layer - both patient and
// doctor can hit this endpoint. Participation is enforced below via the
// session row itself, not the role.
requireAuth();

$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];

// DECISION: message length cap. Spec (T-10) only says "empty or oversized
// message submitted" without a number - defaulting to 2000 chars, flag to
// mentor if there's a contracted limit.
const MAX_MESSAGE_LENGTH = 2000;

// DECISION: max messages returned per poll, to keep AJAX polling payloads
// small on reconnect/catch-up. Client re-polls with the new last_message_id
// if it wants more.
const MESSAGE_PAGE_SIZE = 50;

// getAuthorizedSession() now lives in src/chat/session_helpers.php -
// shared with api/chat/session.php (BE-16) from Day 12 onward, since both
// need the same "is this user a participant on this session" check.
// DECISION: only an active session can be messaged (default $requireActive
// = true in the helper). Pending/ended sessions reject sends and reads
// alike, keeping billing/session lifecycle (Day 10/12) as the single
// source of truth for "is this chat still happening".

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // ---- POLL NEW MESSAGES ----
    $sessionId = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);
    if (!$sessionId) {
        sendError(400, 'session_id is required.');
    }

    // Default to 0 so a first poll with no last_message_id returns full history.
    $lastMessageId = filter_input(INPUT_GET, 'last_message_id', FILTER_VALIDATE_INT);
    if ($lastMessageId === false || $lastMessageId === null) {
        $lastMessageId = 0;
    }

    getAuthorizedSession($pdo, $sessionId, $userId, $role);

    $stmt = $pdo->prepare(
        "SELECT id, session_id, sender_id, message_text, sent_at, is_read
         FROM chat_messages
         WHERE session_id = ? AND id > ?
         ORDER BY id ASC
         LIMIT " . MESSAGE_PAGE_SIZE
    );
    $stmt->execute([$sessionId, $lastMessageId]);
    $messages = $stmt->fetchAll();

    sendSuccess([
        'session_id'  => $sessionId,
        'messages'    => $messages,
        // Lets the client know the new high-water mark for the next poll,
        // even if $messages came back empty.
        'last_message_id' => $messages
            ? (int)end($messages)['id']
            : $lastMessageId,
    ]);
}
elseif ($method === 'POST') {
    // ---- SEND MESSAGE ----
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $missingFields = checkRequiredFields($input, ['session_id', 'message']);
    if (!empty($missingFields)) {
        sendError(400, 'Missing required field(s): ' . implode(', ', $missingFields));
    }

    $sessionId   = (int)$input['session_id'];
    $messageText = trim((string)$input['message']);

    // T-10: empty or oversized message must be rejected, nothing stored.
    if ($messageText === '') {
        sendError(400, 'Message cannot be empty.');
    }
    if (mb_strlen($messageText) > MAX_MESSAGE_LENGTH) {
        sendError(400, 'Message exceeds maximum length of ' . MAX_MESSAGE_LENGTH . ' characters.');
    }

    getAuthorizedSession($pdo, $sessionId, $userId, $role);

    // DECISION: sender_id stores users.id (not patient_profiles.id /
    // doctor_profiles.id). Both roles share the same users table, so this
    // keeps "who sent this" simple for the frontend regardless of role,
    // consistent with $_SESSION['user_id'] being the canonical actor id.
    $stmt = $pdo->prepare(
        "INSERT INTO chat_messages (session_id, sender_id, message_text, sent_at, is_read)
         VALUES (?, ?, ?, NOW(), 0)"
    );
    $stmt->execute([$sessionId, $userId, $messageText]);
    $newMessageId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "SELECT id, session_id, sender_id, message_text, sent_at, is_read
         FROM chat_messages
         WHERE id = ?"
    );
    $stmt->execute([$newMessageId]);
    $message = $stmt->fetch();

    sendSuccess($message, 201);
}
else {
    sendError(405, 'Method not allowed.');
}