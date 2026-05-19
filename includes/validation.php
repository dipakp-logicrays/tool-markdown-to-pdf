<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Validate POSTed form options against the allowed sets and ranges.
 *
 * @throws InvalidArgumentException on any invalid value
 */
function readAndValidateOptions(array $post): array
{
    $opts = DEFAULTS;

    $pageSize = $post['page_size'] ?? DEFAULTS['page_size'];
    if (!in_array($pageSize, PAGE_SIZES, true)) {
        throw new InvalidArgumentException('Invalid page size.');
    }
    $opts['page_size'] = $pageSize;

    $orientation = $post['orientation'] ?? DEFAULTS['orientation'];
    if (!in_array($orientation, ORIENTATIONS, true)) {
        throw new InvalidArgumentException('Invalid orientation.');
    }
    $opts['orientation'] = $orientation;

    foreach (['margin_top', 'margin_right', 'margin_bottom', 'margin_left'] as $k) {
        $v = $post[$k] ?? DEFAULTS[$k];
        if (!is_numeric($v) || $v < 0 || $v > 60) {
            throw new InvalidArgumentException("Invalid {$k} (must be 0–60 mm).");
        }
        $opts[$k] = (float) $v;
    }

    $fs = $post['font_size'] ?? DEFAULTS['font_size'];
    if (!is_numeric($fs) || $fs < 6 || $fs > 24) {
        throw new InvalidArgumentException('Invalid font size (must be 6–24 pt).');
    }
    $opts['font_size'] = (float) $fs;

    $font = trim((string) ($post['font_family'] ?? ''));
    // Only allow letters, numbers, spaces, hyphens, dots — covers all real font names.
    if ($font !== '' && !preg_match('/^[\p{L}\p{N}\s\-\.]+$/u', $font)) {
        throw new InvalidArgumentException('Invalid font family name.');
    }
    $opts['font_family'] = $font;

    return $opts;
}

function validateUpload(?array $upload): array
{
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No file uploaded or upload failed.');
    }
    if ($upload['size'] <= 0) {
        throw new InvalidArgumentException('Uploaded file is empty.');
    }
    if ($upload['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        throw new InvalidArgumentException('File too large (max ' . MAX_UPLOAD_MB . ' MB).');
    }
    $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTS, true)) {
        throw new InvalidArgumentException('Only .md / .markdown files are accepted.');
    }
    return [
        'tmp_name' => $upload['tmp_name'],
        'name'     => basename($upload['name']),
        'title'    => pathinfo($upload['name'], PATHINFO_FILENAME),
    ];
}
