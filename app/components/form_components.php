<?php

if (!function_exists('form_messages')) {
    function form_messages(array $errors = [], array $warnings = [], string $success = ''): void {
        if ($success !== '') {
            echo '<div class="alert success form-alert">' . e($success) . '</div>';
        }
        if ($errors) {
            echo '<div class="alert danger form-alert"><strong>Please fix the following:</strong><ul class="form-list-errors">';
            foreach ($errors as $error) {
                echo '<li>' . e((string)$error) . '</li>';
            }
            echo '</ul></div>';
        }
        if ($warnings) {
            echo '<div class="alert warning form-alert"><strong>Review before submitting:</strong><ul class="form-list-errors">';
            foreach ($warnings as $warning) {
                echo '<li>' . e((string)$warning) . '</li>';
            }
            echo '</ul></div>';
        }
    }
}

if (!function_exists('field_error_text')) {
    function field_error_text(array $fieldErrors, string $field): string {
        return (string)($fieldErrors[$field] ?? '');
    }
}

if (!function_exists('field_class')) {
    function field_class(array $fieldErrors, string $field, string $base = ''): string {
        $classes = trim('form-control ' . $base . (!empty($fieldErrors[$field]) ? ' is-invalid' : ''));
        return $classes;
    }
}

if (!function_exists('field_error_html')) {
    function field_error_html(array $fieldErrors, string $field): string {
        if (empty($fieldErrors[$field])) return '';
        return '<div class="field-error">' . e((string)$fieldErrors[$field]) . '</div>';
    }
}

if (!function_exists('render_text_input')) {
    function render_text_input(string $label, string $name, string $value = '', array $opts = [], array $fieldErrors = []): void {
        $type = (string)($opts['type'] ?? 'text');
        $required = !empty($opts['required']) ? ' required' : '';
        $placeholder = isset($opts['placeholder']) ? ' placeholder="' . e((string)$opts['placeholder']) . '"' : '';
        $list = isset($opts['list']) ? ' list="' . e((string)$opts['list']) . '"' : '';
        $id = e((string)($opts['id'] ?? $name));
        $hint = (string)($opts['hint'] ?? '');
        echo '<label class="form-field"><span class="form-label">' . e($label) . (!empty($opts['required']) ? ' <span class="req">*</span>' : '') . '</span>';
        echo '<input id="' . $id . '" class="' . field_class($fieldErrors, $name) . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . $required . $placeholder . $list . '>';
        echo field_error_html($fieldErrors, $name);
        if ($hint !== '') echo '<small class="field-hint">' . e($hint) . '</small>';
        echo '</label>';
    }
}

if (!function_exists('render_textarea_input')) {
    function render_textarea_input(string $label, string $name, string $value = '', array $opts = [], array $fieldErrors = []): void {
        $rows = (int)($opts['rows'] ?? 3);
        $required = !empty($opts['required']) ? ' required' : '';
        $placeholder = isset($opts['placeholder']) ? ' placeholder="' . e((string)$opts['placeholder']) . '"' : '';
        $id = e((string)($opts['id'] ?? $name));
        $hint = (string)($opts['hint'] ?? '');
        echo '<label class="form-field"><span class="form-label">' . e($label) . (!empty($opts['required']) ? ' <span class="req">*</span>' : '') . '</span>';
        echo '<textarea id="' . $id . '" class="' . field_class($fieldErrors, $name) . '" name="' . e($name) . '" rows="' . $rows . '"' . $required . $placeholder . '>' . e($value) . '</textarea>';
        echo field_error_html($fieldErrors, $name);
        if ($hint !== '') echo '<small class="field-hint">' . e($hint) . '</small>';
        echo '</label>';
    }
}

if (!function_exists('render_select_input')) {
    function render_select_input(string $label, string $name, array $options, $selected = '', array $opts = [], array $fieldErrors = []): void {
        $required = !empty($opts['required']) ? ' required' : '';
        $id = e((string)($opts['id'] ?? $name));
        $hint = (string)($opts['hint'] ?? '');
        echo '<label class="form-field"><span class="form-label">' . e($label) . (!empty($opts['required']) ? ' <span class="req">*</span>' : '') . '</span>';
        echo '<select id="' . $id . '" class="' . field_class($fieldErrors, $name) . '" name="' . e($name) . '"' . $required . '>';
        foreach ($options as $value => $text) {
            $sel = ((string)$value === (string)$selected) ? ' selected' : '';
            echo '<option value="' . e((string)$value) . '"' . $sel . '>' . e((string)$text) . '</option>';
        }
        echo '</select>';
        echo field_error_html($fieldErrors, $name);
        if ($hint !== '') echo '<small class="field-hint">' . e($hint) . '</small>';
        echo '</label>';
    }
}

if (!function_exists('render_checkbox_input')) {
    function render_checkbox_input(string $label, string $name, bool $checked = false, array $opts = []): void {
        $value = e((string)($opts['value'] ?? '1'));
        $hint = (string)($opts['hint'] ?? '');
        echo '<label class="chk form-check"><input type="checkbox" name="' . e($name) . '" value="' . $value . '"' . ($checked ? ' checked' : '') . '> ' . e($label) . '</label>';
        if ($hint !== '') echo '<small class="field-hint block">' . e($hint) . '</small>';
    }
}
