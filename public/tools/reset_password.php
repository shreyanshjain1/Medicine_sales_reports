<?php
require_once __DIR__ . '/../../init.php';
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo 'Deprecated developer tool. Use the manager password reset flow inside the admin user management screen instead.';
