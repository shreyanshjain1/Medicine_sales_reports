<?php

if (!function_exists('ui_modal')) {
  function ui_modal(string $id, string $title, string $bodyHtml, array $opts = []): void {
    $size = trim((string)($opts['size'] ?? 'md'));
    $classes = trim('overlay-panel overlay-panel-' . preg_replace('/[^a-z0-9_-]/i', '', $size) . ' ' . (string)($opts['class'] ?? ''));
    echo '<div class="overlay" id="' . h($id) . '" hidden>';
    echo '<div class="overlay-backdrop" data-close-overlay="' . h($id) . '"></div>';
    echo '<div class="' . h($classes) . '" role="dialog" aria-modal="true" aria-labelledby="' . h($id) . '-title">';
    echo '<div class="overlay-header"><h3 id="' . h($id) . '-title">' . h($title) . '</h3>';
    echo '<button type="button" class="btn btn-light" data-close-overlay="' . h($id) . '">Close</button></div>';
    echo '<div class="overlay-body">' . $bodyHtml . '</div></div></div>';
  }
}

if (!function_exists('ui_drawer')) {
  function ui_drawer(string $id, string $title, string $bodyHtml, array $opts = []): void {
    $side = trim((string)($opts['side'] ?? 'right'));
    $classes = trim('overlay-drawer overlay-drawer-' . preg_replace('/[^a-z0-9_-]/i', '', $side) . ' ' . (string)($opts['class'] ?? ''));
    echo '<div class="overlay" id="' . h($id) . '" hidden>';
    echo '<div class="overlay-backdrop" data-close-overlay="' . h($id) . '"></div>';
    echo '<aside class="' . h($classes) . '" role="dialog" aria-modal="true" aria-labelledby="' . h($id) . '-title">';
    echo '<div class="overlay-header"><h3 id="' . h($id) . '-title">' . h($title) . '</h3>';
    echo '<button type="button" class="btn btn-light" data-close-overlay="' . h($id) . '">Close</button></div>';
    echo '<div class="overlay-body">' . $bodyHtml . '</div></aside></div>';
  }
}
