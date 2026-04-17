<?php

function api_boot(array $opts = []): void {
    $status = (int)($opts['status'] ?? 200);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function api_payload(bool $success, string $message = '', array $data = [], array $errors = []): array {
    return [
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => array_values($errors),
    ];
}

function api_respond(bool $success, string $message = '', array $data = [], int $status = 200, array $errors = []): void {
    api_boot(['status' => $status]);
    echo json_encode(api_payload($success, $message, $data, $errors), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_success(array $data = [], string $message = 'OK', int $status = 200): void {
    api_respond(true, $message, $data, $status, []);
}

function api_error(string $message = 'Bad Request', int $status = 400, array $errors = []): void {
    api_respond(false, $message, [], $status, $errors ?: [$message]);
}

function api_require_method(string $method): void {
    if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? 'GET', $method) !== 0) {
        api_error('Method not allowed.', 405, ['Expected ' . strtoupper($method)]);
    }
}

function api_require_login(): void {
    if (!is_logged_in()) {
        api_error('Authentication required.', 401, ['Please sign in first.']);
    }
}

function api_require_roles(array $roles, string $message = 'Forbidden.'): void {
    $current = my_role();
    $normalized = array_map(static fn($r) => role_norm((string)$r), $roles);
    if (!in_array($current, $normalized, true) && !(is_manager() && in_array('admin', $normalized, true))) {
        api_error($message, 403, [$message]);
    }
}

function api_db_query_or_fail(mysqli $db, string $sql, string $message = 'Database query failed.'): mysqli_result {
    $res = $db->query($sql);
    if (!$res) {
        api_error($message, 500, [$db->error ?: $message]);
    }
    return $res;
}


function api_json_payload(bool $success, string $message = '', array $data = [], array $errors = []): array {
    return api_payload($success, $message, $data, $errors);
}

function api_json_success(array $data = [], string $message = 'OK', int $status = 200): void {
    api_success($data, $message, $status);
}

function api_json_error(string $message = 'Bad Request', int $status = 400, array $errors = []): void {
    api_error($message, $status, $errors);
}
