<?php
declare(strict_types=1);

$root = getcwd();
if ($root === false) {
    fwrite(STDERR, "Unable to determine working directory.\n");
    exit(1);
}

$patterns = [
    "#href\\s*=\\s*[\"'](?:report_view|report_edit|report_add|reports|task_view|task_edit|task_delete|doctors_master|hospitals_master|medicines_master)\\.php#i",
    "#action\\s*=\\s*[\"'](?:report_view|report_edit|report_add|reports|task_view|task_edit|task_delete|doctors_master|hospitals_master|medicines_master)\\.php#i",
    "#fetch\\(\\s*[\"']api/(?:chart_data|api_events|api_doctors|api_sync_reports)\\.php#i",
    "#window\\.location\\s*=\\s*[\"'](?:report_view|report_edit|report_add|reports|task_view|task_edit|task_delete)\\.php#i",
    "#header\\s*\\(\\s*[\"']Location:\\s*(?:report_view|report_edit|report_add|reports|task_view|task_edit|task_delete)\\.php#i",
];

$errors = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'public', FilesystemIterator::SKIP_DOTS)
);

foreach ($rii as $file) {
    $path = $file->getPathname();
    if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
        continue;
    }

    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $content = file_get_contents($path);
    if ($content === false) {
        $errors[] = "Could not read {$rel}";
        continue;
    }

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content, $matches)) {
            $errors[] = "{$rel} still uses brittle route syntax: {$matches[0]}";
        }
    }
}

if ($errors) {
    fwrite(STDERR, "Route reference smoke check failed:\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}

echo "Route reference smoke check passed.\n";
