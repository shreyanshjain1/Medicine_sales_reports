<?php
if (!function_exists('ui_attr_string')) {
    function ui_attr_string(array $attrs = []): string {
        $out = [];
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === false) continue;
            if ($v === true) { $out[] = e((string)$k); continue; }
            $out[] = e((string)$k) . '="' . e((string)$v) . '"';
        }
        return $out ? ' ' . implode(' ', $out) : '';
    }
}

if (!function_exists('ui_page_hero')) {
    function ui_page_hero(string $title, string $subtitle = '', string $actionsHtml = ''): void {
        echo '<div class="crm-hero ui-hero">';
        echo '<div><h2>' . e($title) . '</h2>';
        if ($subtitle !== '') echo '<div class="subtle">' . e($subtitle) . '</div>';
        echo '</div>';
        if ($actionsHtml !== '') echo '<div class="actions-inline">' . $actionsHtml . '</div>';
        echo '</div>';
    }
}

if (!function_exists('ui_stat_card')) {
    function ui_stat_card(string $label, $value, string $hint = '', string $tone = 'default'): void {
        $toneClass = 'ui-tone-' . preg_replace('/[^a-z0-9_-]/i', '', strtolower($tone));
        echo '<div class="card summary-card ui-stat-card ' . e($toneClass) . '">';
        echo '<div class="summary-label">' . e($label) . '</div>';
        echo '<div class="summary-value">' . e((string)$value) . '</div>';
        if ($hint !== '') echo '<div class="summary-hint">' . e($hint) . '</div>';
        echo '</div>';
    }
}

if (!function_exists('ui_badge')) {
    function ui_badge(string $text, string $variant = 'neutral'): string {
        $variant = preg_replace('/[^a-z0-9_-]/i', '', strtolower($variant));
        return '<span class="badge ui-badge ' . e($variant) . '">' . e($text) . '</span>';
    }
}

if (!function_exists('ui_section_head')) {
    function ui_section_head(string $title, string $meta = '', string $actionsHtml = ''): void {
        echo '<div class="flex-between ui-section-head">';
        echo '<div><h2 class="titlecase">' . e($title) . '</h2>';
        if ($meta !== '') echo '<div class="muted">' . e($meta) . '</div>';
        echo '</div>';
        if ($actionsHtml !== '') echo '<div class="actions-inline">' . $actionsHtml . '</div>';
        echo '</div>';
    }
}

if (!function_exists('ui_empty_state')) {
    function ui_empty_state(string $message, string $actionHtml = '', string $icon = '•'): void {
        echo '<div class="ui-empty-state">';
        echo '<div class="ui-empty-icon">' . e($icon) . '</div>';
        echo '<div class="ui-empty-message">' . e($message) . '</div>';
        if ($actionHtml !== '') echo '<div class="ui-empty-actions">' . $actionHtml . '</div>';
        echo '</div>';
    }
}
