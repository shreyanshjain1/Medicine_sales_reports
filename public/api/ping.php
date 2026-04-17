<?php
require_once __DIR__ . '/../../init.php';
if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? 'GET', 'GET') !== 0) {
    api_json_error('Method not allowed.', 405, ['Expected GET']);
}
api_json_success(['timestamp' => gmdate('c')], 'pong');
