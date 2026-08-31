<?php
/**
 * BE-19b: Mark Support Enquiry Resolved
 * POST /api/support/update-status.php  { enquiry_id }
 *
 * Auth: admin only.
 *
 * DECISION: one-directional (open -> resolved) only, no reopen path -
 * mirrors the "rejection is final" precedent from BE-11 doctor approval.
 * Flag to mentor if support tickets should be reopenable (e.g. enquirer
 * follows up after being marked resolved).
 *
 * DECISION: this endpoint - and the "mark resolved" concept itself -
 * isn't in the original BE-19 contract row (which only specifies
 * GET/POST on enquiries.php). Built at your request; worth confirming
 * with mentor whether this should be numbered as its own ticket
 * (e.g. BE-19b) for the plan/tracking sheet.
 *
 * Include depth: api/support/ -> ../../
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/auth/middleware.php';
require_once __DIR__ . '/../../src/validation/validation.php';
require_once __DIR__ . '/../../src/response.php'; // sendSuccess() / sendError()

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed.');
}

$input = json_decode(file_get_contents('php://input'), true);

if ($input === null) {
    sendError(400, 'Invalid request');
}

$missingFields = checkRequiredFields($input, ['enquiry_id']);
if (!empty($missingFields)) {
    sendError(400, 'Missing required field(s): ' . implode(', ', $missingFields));
}

$enquiryId = filter_var($input['enquiry_id'], FILTER_VALIDATE_INT);
if (!$enquiryId) {
    sendError(400, 'enquiry_id must be a valid integer.');
}

$stmt = $pdo->prepare("SELECT id, status FROM support_enquiries WHERE id = ?");
$stmt->execute([$enquiryId]);
$enquiry = $stmt->fetch();

if (!$enquiry) {
    sendError(404, 'Enquiry not found.');
}

if ($enquiry['status'] === 'resolved') {
    sendError(409, 'This enquiry is already marked resolved.');
}

$stmt = $pdo->prepare("UPDATE support_enquiries SET status = 'resolved' WHERE id = ?");
$stmt->execute([$enquiryId]);

sendSuccess([
    'id'     => $enquiryId,
    'status' => 'resolved',
]);