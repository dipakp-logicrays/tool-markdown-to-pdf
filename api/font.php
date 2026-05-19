<?php
declare(strict_types=1);

/**
 * Serve a single system font file as binary, with strong caching, so the
 * browser can use it via @font-face for in-dropdown previews.
 *
 * Used only when FONT_SOURCE=system. The lookup is via fontconfig (so we
 * only ever serve fonts that fc-list confirms exist), and the path must
 * fall within the whitelist in includes/fonts.php — this prevents path
 * traversal even if the family name is forged.
 *
 * Query: ?family=<URL-encoded family name>
 */

require_once __DIR__ . '/../includes/fonts.php';

$family = isset($_GET['family']) ? (string) $_GET['family'] : '';
if ($family === '') {
    http_response_code(400);
    exit;
}

$entry = findInstalledFont($family);
if ($entry === null) {
    http_response_code(404);
    exit;
}

if (!isFontPathAllowed($entry['file'])) {
    http_response_code(403);
    exit;
}

$file = realpath($entry['file']);
if ($file === false || !is_readable($file)) {
    http_response_code(404);
    exit;
}

$mime = match ($entry['format']) {
    'truetype'   => 'font/ttf',
    'opentype'   => 'font/otf',
    'woff'       => 'font/woff',
    'woff2'      => 'font/woff2',
    'collection' => 'font/collection',
    default      => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
// One-year immutable cache: the file content for a given family/path
// doesn't change in practice, and api/font.php is keyed by family name.
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . (string) filesize($file));
header('Access-Control-Allow-Origin: *');
readfile($file);
