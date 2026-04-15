<?php
// Lightweight online check endpoint for PWA logic.
// No auth required; returns 204 quickly.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
http_response_code(204);
exit;
