<?php
declare(strict_types=1);

/**
 * System font discovery via fontconfig.
 *
 * Used when FONT_SOURCE=system. Returns one entry per family, picking
 * a "regular" variant when multiple are available so previews render
 * in the unweighted face. Each entry includes the on-disk file path
 * so api/font.php can stream the bytes to the browser for in-dropdown
 * preview rendering.
 */

/**
 * Whitelisted prefixes for serving font files. api/font.php refuses to
 * serve anything outside these paths.
 */
const FONT_DIR_WHITELIST = [
    '/usr/share/fonts',
    '/usr/local/share/fonts',
    '/var/lib/fonts',
    '/home',
];

/**
 * @return array<int, array{family: string, file: string, format: string}>
 */
function getInstalledFonts(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // Apache invokes PHP with an empty $HOME, which makes fontconfig skip
    // user-level font dirs (~/.local/share/fonts/, ~/.fonts/, etc.).
    // Pass HOME explicitly so we get the same fc-list output as a normal
    // shell. Fall back to /tmp if we can't determine a home dir.
    $home = '/tmp';
    if (function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && !empty($pw['dir']) && is_dir($pw['dir'])) {
            $home = $pw['dir'];
        }
    }

    $output = [];
    $rc = -1;
    exec('HOME=' . escapeshellarg($home) . ' fc-list : family file 2>/dev/null', $output, $rc);
    if ($rc !== 0 || empty($output)) {
        $cache = [];
        return $cache;
    }

    // fc-list output for `: family file` is:
    //   "/path/to/file.ttf: Family Name,Variant Name"
    // (path first, then ": ", then comma-separated family + variants).
    //
    // Build one entry per family, preferring the file that looks like
    // the regular face (no "bold", "italic", etc. in the filename).
    $picked = [];
    foreach ($output as $line) {
        $sepPos = strpos($line, ': ');
        if ($sepPos === false) {
            continue;
        }
        $file       = substr($line, 0, $sepPos);
        $familyPart = substr($line, $sepPos + 2);

        // Take the canonical family name (first comma-segment)
        $familyTokens = explode(',', $familyPart, 2);
        $family = trim(stripcslashes($familyTokens[0]));
        if ($family === '' || $file === '' || !is_readable($file)) {
            continue;
        }

        $rank = rankFontFile(basename($file));
        if (!isset($picked[$family]) || $rank < $picked[$family]['rank']) {
            $picked[$family] = [
                'family' => $family,
                'file'   => $file,
                'rank'   => $rank,
                'format' => fontFormat($file),
            ];
        }
    }

    $list = array_values($picked);
    usort($list, static fn($a, $b) => strnatcasecmp($a['family'], $b['family']));

    // Drop the internal rank field before returning
    foreach ($list as &$entry) {
        unset($entry['rank']);
    }
    unset($entry);

    $cache = $list;
    return $cache;
}

/**
 * Lower rank = better candidate for "regular" preview face.
 *
 * Strategy:
 *   1. Strong bonus for an explicit Regular/Roman/Normal/Book/Latin marker.
 *   2. Strong penalty for italic/oblique markers (including short two-letter
 *      abbreviations like -RI / -BI / -LI which signify Regular-Italic etc.).
 *   3. Penalty for weight modifiers (Hair, Thin, Light, Medium, Bold, Black,
 *      Heavy, Ultra, Extra, Semi/Demi).
 *   4. Light penalty for width modifiers (Condensed, Extended, etc.).
 *   5. Filename length as final tie-breaker.
 */
function rankFontFile(string $basename): int
{
    // Strip extension so it doesn't pollute substring checks
    $stem = strtolower(preg_replace('/\.(ttf|otf|woff2?|ttc|pfb)$/i', '', $basename));

    // Big bonus if the filename explicitly declares it's the regular face.
    // Includes common shorthands: -R / -Rg / -Reg / -Regular / -Roman / -Latin.
    if (preg_match('/(^|[-_])(regular|roman|normal|book|latin|reg|rg|r)(?=$|[-_.])/', $stem)) {
        return 0;
    }

    $rank = 1; // baseline for "no explicit regular marker"

    // Italic / oblique — heavy penalty.
    if (preg_match('/(^|[-_])(italic|oblique|it|obl)(?=$|[-_.])/', $stem)
        || preg_match('/[-_][bsmhltexd]?(ri|bi|li|mi|si|ti|ei|hi)(?=$|[-_.])/', $stem)
    ) {
        $rank += 100;
    }

    // Weight modifiers — full words
    foreach (['hair', 'thin', 'ultralight', 'extralight', 'light', 'medium',
              'semibold', 'demibold', 'bold', 'extrabold', 'ultrabold',
              'black', 'heavy', 'ultra', 'extra', 'semi', 'demi'] as $w) {
        if (strpos($stem, $w) !== false) {
            $rank += 10;
        }
    }
    // Weight modifiers — 2-letter abbreviations after a hyphen/underscore
    //   -Th (Thin), -Lt (Light), -Md (Medium), -Bd (Bold), -Bl (Black),
    //   -Hv (Heavy), -Hr (Hair), -El (ExtraLight), -Ul (UltraLight),
    //   -Sb (SemiBold), -Xb (ExtraBold), -Sl (Slab/Slim)
    if (preg_match('/[-_](th|lt|md|bd|bl|hv|hr|el|ul|sb|xb|sl)(?=$|[-_.])/', $stem)) {
        $rank += 10;
    }

    // Width modifiers
    foreach (['condensed', 'compressed', 'extended', 'expanded', 'narrow', 'wide'] as $w) {
        if (strpos($stem, $w) !== false) {
            $rank += 5;
        }
    }
    if (preg_match('/[-_](cn|cd|nr|ex|wd|sh)(?=$|[-_.])/', $stem)) {
        $rank += 5;
    }

    // Filename length as a final tiebreaker (shorter wins)
    $rank += strlen($stem) / 1000.0;
    return (int) round($rank);
}

/**
 * Map font filename → CSS @font-face format hint.
 */
function fontFormat(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return match ($ext) {
        'ttf'   => 'truetype',
        'otf'   => 'opentype',
        'woff'  => 'woff',
        'woff2' => 'woff2',
        'ttc'   => 'collection',
        default => 'truetype',
    };
}

/**
 * Look up a single font by family name. Returns ['family', 'file', 'format']
 * or null if not found in the installed list.
 */
function findInstalledFont(string $family): ?array
{
    foreach (getInstalledFonts() as $f) {
        if ($f['family'] === $family) {
            return $f;
        }
    }
    return null;
}

/**
 * True if the given file path is within an allowed font directory.
 * Use realpath() so symlinks can't escape the whitelist.
 */
function isFontPathAllowed(string $file): bool
{
    $real = realpath($file);
    if ($real === false) {
        return false;
    }
    foreach (FONT_DIR_WHITELIST as $prefix) {
        if (strpos($real, $prefix . '/') === 0) {
            return true;
        }
    }
    return false;
}
