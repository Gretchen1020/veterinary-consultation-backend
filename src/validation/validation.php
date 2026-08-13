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
function checkRequiredFields(array $input, array $requiredFields): array {
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            $missingFields[] = $field;
            }
    }
    return $missingFields;
}
