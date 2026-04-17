<?php

function api_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        api_error('Invalid JSON payload.', 400, ['Request body is required.']);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('Invalid JSON payload.', 400, ['Body must be valid JSON.']);
    }
    return $data;
}

function api_get_string(array $source, string $key, bool $required = false, ?int $maxLen = null, ?string $label = null): string {
    $label = $label ?: $key;
    $value = trim((string)($source[$key] ?? ''));
    if ($required && $value === '') {
        api_error('Validation failed.', 422, ["{$label} is required."]);
    }
    if ($maxLen !== null && mb_strlen($value) > $maxLen) {
        api_error('Validation failed.', 422, ["{$label} must be at most {$maxLen} characters."]);
    }
    return $value;
}

function api_get_int(array $source, string $key, bool $required = false, ?int $min = null, ?int $max = null, ?string $label = null): ?int {
    $label = $label ?: $key;
    if (!array_key_exists($key, $source) || $source[$key] === '' || $source[$key] === null) {
        if ($required) {
            api_error('Validation failed.', 422, ["{$label} is required."]);
        }
        return null;
    }
    if (filter_var($source[$key], FILTER_VALIDATE_INT) === false) {
        api_error('Validation failed.', 422, ["{$label} must be an integer."]);
    }
    $value = (int)$source[$key];
    if ($min !== null && $value < $min) {
        api_error('Validation failed.', 422, ["{$label} must be at least {$min}."]);
    }
    if ($max !== null && $value > $max) {
        api_error('Validation failed.', 422, ["{$label} must be at most {$max}."]);
    }
    return $value;
}

function api_get_bool(array $source, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $source)) return $default;
    $value = $source[$key];
    if (is_bool($value)) return $value;
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function api_require_query_string(string $key, ?string $label = null, ?int $maxLen = null): string {
    return api_get_string($_GET, $key, true, $maxLen, $label ?: $key);
}

function api_require_post_csrf(array $payload, string $tokenKey = '_token'): void {
    $token = (string)($payload[$tokenKey] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        api_error('Invalid CSRF token.', 403, ['CSRF token mismatch.']);
    }
}

function api_assert_array($value, string $label = 'items'): array {
    if (!is_array($value)) {
        api_error('Validation failed.', 422, ["{$label} must be an array."]);
    }
    return $value;
}
