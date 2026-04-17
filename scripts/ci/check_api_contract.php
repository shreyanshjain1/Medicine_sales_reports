<?php
declare(strict_types=1);

$apiDir = getcwd() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api';
if (!is_dir($apiDir)) {
    fwrite(STDERR, "API directory not found.\n");
    exit(1);
}

$files = glob($apiDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
if (!$files) {
    fwrite(STDERR, "No API files found.\n");
    exit(1);
}

$errors = [];
foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        $errors[] = "Could not read " . basename($file);
        continue;
    }

    $hasSuccess = str_contains($content, 'api_json_success(');
    $hasError = str_contains($content, 'api_json_error(');
    if (!$hasSuccess || !$hasError) {
        $errors[] = basename($file) . ' should use both api_json_success() and api_json_error()';
    }
}

if ($errors) {
    fwrite(STDERR, "API contract check failed:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "API contract check passed for " . count($files) . " endpoints.\n";
