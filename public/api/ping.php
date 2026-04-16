<?php
require_once __DIR__ . '/../../init.php';
api_require_method('GET');
api_success(['timestamp' => gmdate('c')], 'pong');
