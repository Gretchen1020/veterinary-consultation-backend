<?php

function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidPin(string $pin): bool {
    return preg_match('/^\d{4}$/', $pin) === 1;
}

function isValidDate(string $date, string $format = 'Y-m-d'): bool {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function isValidExperience($experience): bool {
    return is_numeric($experience) && (int)$experience >= 0 && (int)$experience <= 60;
}

function isValidContact(string $contact): bool {
    return preg_match('/^\d{7,15}$/', $contact) === 1;
}
function checkRequiredFields(array $input, array $requiredFields): array {
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            $missingFields[] = $field;
            }
    }
    return $missingFields;
}

function validateUploadedFile(string $fieldName, int $maxSize, array $allowedTypes, bool $isOptional): ?array {

    // 1. presence check (according to $isOptional)
    if (!isset($_FILES[$fieldName])) {
        return ['error' => $isOptional ? null : "File upload for '$fieldName' is required.", 'mime_type' => null];
    }

    $file = $_FILES[$fieldName];

    // 2. error code check — always reject a broken upload, regardless of $isOptional
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => "File upload for '$fieldName' failed (error code: {$file['error']}).", 'mime_type' => null];
    }

    // 3. size check
    if ($file['size'] > $maxSize) {
        return ['error' => "File for '$fieldName' exceeds the maximum allowed size (" . ($maxSize / 1024 / 1024) . " MB).", 'mime_type' => null];
    }

    // 4. real mime-type check via finfo_file()
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedTypes, true)) {
        return ['error' => "File for '$fieldName' must be of type: " . implode(', ', $allowedTypes) . ".", 'mime_type' => null];
    }

    // 5. success
    return ['error' => null, 'mime_type' => $mimeType];
}

function uploadFile(string $fieldName, string $destinationDir, string $newFileName, string $mimeType): array {
    if (!isset($_FILES[$fieldName])) {
        return ['error' => "File not uploaded.",'file_path' => null];
    }

    $mimeToExtension = [
    'application/pdf' => 'pdf',
    'image/jpeg'       => 'jpg',
    'image/png'        => 'png',
    ];

    $uploadDir = realpath(__DIR__ . '/' . $destinationDir);
    if ($uploadDir === false) {
        return ['error' => "Upload directory does not exist or is inaccessible.", 'file_path' => null];
    }

    $uniqueId = bin2hex(random_bytes(16));
    $extension = $mimeToExtension[$mimeType] ?? null;
    $filename = $uniqueId . $newFileName.'.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    $file = $_FILES[$fieldName];
   $moveSucceeded = move_uploaded_file($file['tmp_name'], $destination);

return [
    'error' => $moveSucceeded ? null : "Failed to move uploaded file for '$fieldName'.",
    'file_path' => $moveSucceeded ? $destination : null,
];
}
