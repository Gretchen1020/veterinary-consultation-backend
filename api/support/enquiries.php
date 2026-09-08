<?php
/**
 * BE-19: Support Enquiries
 * POST /api/support/enquiries.php  -> public, submit an enquiry
 * GET  /api/support/enquiries.php  -> admin only, list enquiries
 *
 * Table already exists in the live schema (support_enquiries), no
 * migration needed - see docs/schema.sql.
 *
 * DECISION: contract only specifies GET/POST. The status column
 * (open/resolved) implies a future "mark resolved" action, but no
 * update endpoint is in scope here - flag to mentor if/when that's
 * needed rather than adding it unprompted.
 *
 * Include depth: api/support/ -> ../../
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/auth/session.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/response.php'; // sendSuccess() / sendError()
require_once __DIR__ . '/../../src/notifications/notification_service.php';

// BUGFIX: const declarations must sit at top-level file scope in PHP -
// they cannot live inside an if/elseif block. Originally declared inline
// per-branch below, which threw "unexpected token const" on line 62.

const MAX_SUBJECT_LENGTH = 200;
const MAX_MESSAGE_LENGTH = 2000;
const RATE_LIMIT_WINDOW_SECONDS = 60;
const LIST_DEFAULT_LIMIT = 10;
const LIST_MAX_LIMIT = 50;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // ---- SUBMIT ENQUIRY (public) ----
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        sendError(400, 'Invalid request');
    }

    $name    = $input['name']    ?? null;
    $email   = $input['email']   ?? null;
    $contact = $input['contact'] ?? null; // optional per schema
    $subject = $input['subject'] ?? null;
    $message = $input['message'] ?? null;

    $missingFields = checkRequiredFields($input, ['name', 'email', 'subject', 'message']);
    if (!empty($missingFields)) {
        sendError(400, 'Missing required field(s): ' . implode(', ', $missingFields));
    }

    if (!isValidEmail($email)) {
        sendError(400, 'Invalid email address.');
    }

    // Contact is optional in the schema, but if provided it should still
    // pass the same format check 
    if ($contact !== null && $contact !== '' && !isValidContact($contact)) {
        sendError(400, 'Invalid contact number.');
    }

    $subject = trim((string)$subject);
    $message = trim((string)$message);

    // DECISION: no contracted length limits for subject/message - reusing
    // the 2000-char cap precedent from chat messages (BE-15) for message,
    // and a shorter cap for subject. Flag to mentor if different limits
    // are wanted. (Constants declared at top of file - see BUGFIX note.)
    if ($subject === '') {
        sendError(400, 'Subject cannot be empty.');
    }
    if ($message === '') {
        sendError(400, 'Message cannot be empty.');
    }
    if (mb_strlen($subject) > MAX_SUBJECT_LENGTH) {
        sendError(400, 'Subject exceeds maximum length of ' . MAX_SUBJECT_LENGTH . ' characters.');
    }
    if (mb_strlen($message) > MAX_MESSAGE_LENGTH) {
        sendError(400, 'Message exceeds maximum length of ' . MAX_MESSAGE_LENGTH . ' characters.');
    }

    // T-16: spam/rapid-fire submissions must be prevented. No rate-limiting
    // infra exists yet (no Redis, no dedicated throttle table), so this
    // checks the support_enquiries table itself: same email submitting
    // again inside the cooldown window is rejected. DECISION: values below
    // (1 submission per 60 seconds per email) are a starting point, not
    // mentor-confirmed - flag if a different window/threshold is wanted,
    // or if IP-based limiting is expected instead of/in addition to email.

    $rateStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM support_enquiries
         WHERE email = ? AND created_at > (NOW() - INTERVAL ? SECOND)"
    );
    $rateStmt->execute([$email, RATE_LIMIT_WINDOW_SECONDS]);
    if ((int)$rateStmt->fetchColumn() > 0) {
        sendError(429, 'Please wait before submitting another enquiry.');
    }

    // BUGFIX: this POST branch is deliberately public, so it never calls
    // requireAuth() - and requireAuth() is what actually starts/
    // resumes the session on every other endpoint that reads $_SESSION
    // successfully. Without that call here, $_SESSION can stay empty even
    // with a valid session cookie present, silently making every
    // submission look anonymous. Explicit guard so a logged-in submitter
    // is still linked correctly without requiring auth.
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Link to a logged-in user if a session already exists, without
    // requiring one - endpoint stays public either way.
    $userId = $_SESSION['user_id'] ?? null;

    $stmt = $pdo->prepare(
        "INSERT INTO support_enquiries (user_id, name, email, contact, subject, message, status)
         VALUES (?, ?, ?, ?, ?, ?, 'open')"
    );
    $stmt->execute([$userId, $name, $email, $contact, $subject, $message]);
    $enquiryId = (int)$pdo->lastInsertId();

    // *** ADDED — admin notification ***
    // No explicit transaction in this branch, so "after
    // Wrapped defensively, same as register.php: a broken
    // notification insert must never block a genuinely successful
    // enquiry submission from returning its response. Fires regardless
    // of whether the submitter was logged in or anonymous — an enquiry
    // is equally real either way.
    try {
        notifyAllAdmins(
            $pdo,
            'NEW_SUPPORT_ENQUIRY',
            'New support enquiry',
            "{$name} submitted a support enquiry: {$subject}",
            'support_enquiry',
            $enquiryId
        );
    } catch (Throwable $e) {
        error_log('Failed to create NEW_SUPPORT_ENQUIRY notification: ' . $e->getMessage());
    }

    sendSuccess([
        'id'      => $enquiryId,
        'status'  => 'open',
        'name'    => $name,
        'email'   => $email,
        'contact' => $contact,
        'subject' => $subject,
        'message' => $message,
    ], 201);
}
elseif ($method === 'GET') {
    // ---- LIST ENQUIRIES (admin only) ----
    requireAuth('admin');

    // DECISION: pagination matching the BE-17 history.php precedent -
    // default 10/max 50 per page. (Constants declared at top of file -
    // see BUGFIX note.)
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    if (!$page || $page < 1) {
        $page = 1;
    }

    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
    if (!$limit || $limit < 1) {
        $limit = LIST_DEFAULT_LIMIT;
    }
    if ($limit > LIST_MAX_LIMIT) {
        $limit = LIST_MAX_LIMIT;
    }
    $offset = ($page - 1) * $limit;

    $status = $_GET['status'] ?? null;
    $allowedStatuses = ['open', 'resolved'];
    if ($status !== null && !in_array($status, $allowedStatuses, true)) {
        sendError(400, 'Invalid status filter.');
    }

    if ($status !== null) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM support_enquiries WHERE status = ?");
        $countStmt->execute([$status]);
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM support_enquiries");
    }
    $total = (int)$countStmt->fetchColumn();

    if ($status !== null) {
        $stmt = $pdo->prepare(
            "SELECT id, user_id, name, email, contact, subject, message, status, created_at
             FROM support_enquiries
             WHERE status = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $status, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, user_id, name, email, contact, subject, message, status, created_at
             FROM support_enquiries
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $enquiries = $stmt->fetchAll();

    sendSuccess([
        'page'      => $page,
        'limit'     => $limit,
        'total'     => $total,
        'enquiries' => $enquiries,
    ]);
}
else {
    sendError(405, 'Method not allowed.');
}