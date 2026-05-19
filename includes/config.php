<?php
declare(strict_types=1);

/**
 * Central constants & default form values.
 * Required by every other PHP file via require_once.
 */

const PANDOC_BIN    = '/usr/bin/pandoc';
const CHROME_BIN    = '/usr/bin/google-chrome';
const PROJECT_ROOT  = __DIR__ . '/..';
const WORK_DIR      = __DIR__ . '/../tmp';
const MAX_UPLOAD_MB = 16;

const DEFAULTS = [
    'page_size'     => 'A4',
    'orientation'   => 'portrait',
    'margin_top'    => 16,
    'margin_right'  => 12,
    'margin_bottom' => 16,
    'margin_left'   => 12,
    'font_size'     => 10.5,
    'font_family'   => '',
];

const PAGE_SIZES   = ['A4', 'Letter', 'Legal', 'A3', 'A5'];
const ORIENTATIONS = ['portrait', 'landscape'];
const ALLOWED_EXTS = ['md', 'markdown', 'mdown', 'mkd'];
