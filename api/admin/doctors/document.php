<?php
require_once __DIR__ . '/../../../src/response.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../src/validation/validation.php';
require_once __DIR__ . '/../../../src/auth/session.php';
require_once __DIR__ . '/../../../src/auth/middleware.php';

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') 
{
    sendError(405, 'Method Not Allowed');
}

    $documentId = $_GET['document_id'] ?? null;

    if ($documentId === null || !ctype_digit((string)$documentId)) {
        sendError(400, 'Invalid or missing document_id');
    }

    $stmt = $pdo->prepare("SELECT file_path, mime_type, original_name
                            FROM doctor_documents
                            WHERE id = ?");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch();

    if (!$document) {
        sendError(404, 'Document not found');
    }

    // Confirm the file still exists on disk before trying to stream it —
    // a DB row could outlive the actual file (e.g. manual cleanup, migration gap)
    if (!file_exists($document['file_path'])) {
        sendError(404, 'File not found on server');
    }

    // Confirms the resolved file actually lives inside the expected
    // storage directory before hand-over to readfile(). 
    $storageBase = realpath(__DIR__ . '/../../../storage/doctor_documents');
    $resolvedPath = realpath($document['file_path']);

    if ($resolvedPath === false || strpos($resolvedPath, $storageBase) !== 0) {
        sendError(404, 'File not found on server');
    }

    // original_name is user-controlled (whatever filename the doctor's
    // browser sent at upload) and lands directly in a header below —
    // strip quotes so it can't break out of the filename="..." value.
    $safeName = str_replace('"', '', $document['original_name']);

    // Stream the bytes — this replaces sendJson() entirely for this response
    header('Content-Type: ' . $document['mime_type']);
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($document['file_path']));
    readfile($document['file_path']);
    exit;