<?php
declare(strict_types=1);

$root = getcwd();
if ($root === false) {
    fwrite(STDERR, "Unable to determine working directory.\n");
    exit(1);
}

$errors = [];
$checked = 0;
$skipFragments = [DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR];

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$resolveRelative = static function (string $baseDir, string $target) use ($root): ?string {
    $target = trim($target);
    if ($target === '' || preg_match('/^(https?:)?\/\//i', $target) || str_contains($target, 'php://')) {
        return null;
    }
    $target = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target);
    if (str_starts_with($target, '__ROOT__' . DIRECTORY_SEPARATOR)) {
        $target = substr($target, 9);
        return $root . DIRECTORY_SEPARATOR . ltrim($target, DIRECTORY_SEPARATOR);
    }
    return $baseDir . DIRECTORY_SEPARATOR . ltrim($target, DIRECTORY_SEPARATOR);
};

$checkFile = static function (string $sourcePath, string $targetPath) use (&$errors, &$checked, $root): void {
    $normalized = realpath($targetPath);
    if ($normalized === false || !file_exists($normalized)) {
        if (basename($targetPath) === 'config.php' && file_exists(dirname($targetPath) . DIRECTORY_SEPARATOR . 'config.example.php')) {
            $checked++;
            return;
        }
        $errors[] = sprintf('%s includes missing file: %s', str_replace($root . DIRECTORY_SEPARATOR, '', $sourcePath), $targetPath);
        return;
    }
    $checked++;
};

foreach ($rii as $file) {
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

    $dir = dirname($path);

    if (preg_match_all("/\\b(?:require|require_once|include|include_once)\\s*(?:\\(|)\\s*([\"'])((?:(?!\1).)+)\\1/s", $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $resolved = $resolveRelative($dir, $match[2]);
            if ($resolved !== null) {
                $checkFile($path, $resolved);
            }
        }
    }

    if (preg_match_all("/\\b(?:require|require_once|include|include_once)\\s*(?:\\(|)\\s*__DIR__\\s*\\.\\s*([\"'])([^\"']+)\\1/s", $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $resolved = $resolveRelative($dir, $match[2]);
            if ($resolved !== null) {
                $checkFile($path, $resolved);
            }
        }
    }

    if (basename($path) === 'files.php' && str_contains($content, 'app_bootstrap_files')) {
        if (preg_match_all("/[\"'](app\\/[^\"']+\\.php)[\"']/", $content, $matches)) {
            foreach ($matches[1] as $bootstrapTarget) {
                $resolved = $resolveRelative($root, '__ROOT__/' . $bootstrapTarget);
                if ($resolved !== null) {
                    $checkFile($path, $resolved);
                }
            }
        }
    }
}

if ($errors) {
    fwrite(STDERR, "Include smoke check failed:\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}

echo "Include smoke check passed for {$checked} include/require/bootstrap references.\n";
