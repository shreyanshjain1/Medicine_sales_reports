<?php
declare(strict_types=1);

$root = getcwd();
if ($root === false) {
    fwrite(STDERR, "Unable to determine working directory.\n");
    exit(1);
}

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$errors = [];
$checked = 0;
$skipFragments = [DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR];

foreach ($rii as $file) {
    /** @var SplFileInfo $file */
    $path = $file->getPathname();
    if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
        continue;
    }
    foreach ($skipFragments as $fragment) {
        if (strpos($path, $fragment) !== false) {
            continue 2;
        }
    }

    $content = file_get_contents($path);
    if ($content === false) {
        $errors[] = "Could not read {$path}";
        continue;
    }

    $pattern = '/\b(require|require_once|include|include_once)\s*(?:\(|)\s*([\"\'])([^\"\']+)\2/s';
    if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
        continue;
    }

    $dir = dirname($path);
    foreach ($matches as $match) {
        $target = $match[3];
        if ($target === '' || preg_match('/^(https?:)?\/\//i', $target)) {
            continue;
        }
        if (strpos($target, '$') !== false) {
            continue;
        }
        if (str_contains($target, 'php://')) {
            continue;
        }

        $resolved = $dir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target);
        $normalized = realpath($resolved);
        if ($normalized === false || !file_exists($normalized)) {
            $errors[] = sprintf('%s includes missing file: %s', str_replace($root . DIRECTORY_SEPARATOR, '', $path), $target);
            continue;
        }
        $checked++;
    }
}

if ($errors) {
    fwrite(STDERR, "Include smoke check failed:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "Include smoke check passed for {$checked} include/require statements.\n";
