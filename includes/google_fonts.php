<?php
declare(strict_types=1);

/**
 * Google Fonts catalog — loaded from the bundled JSON snapshot at
 * `assets/data/google_fonts.json`, sorted by popularity.
 *
 * To refresh the snapshot from upstream (gwfh.mranftl.com — a community
 * mirror that doesn't need a Google API key), run `./bin/refresh_fonts`.
 *
 * Each entry has shape `{family, category, weights}` where weights is an
 * array of integers (e.g. [300, 400, 700]). Italics are stripped at
 * snapshot time so the data only describes available upright weights.
 */

const GOOGLE_FONTS_JSON = __DIR__ . '/../assets/data/google_fonts.json';

/**
 * @return array<int, array{family: string, category: string, weights: int[]}>
 */
function getGoogleFonts(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!is_readable(GOOGLE_FONTS_JSON)) {
        $cache = [];
        return $cache;
    }
    $data = json_decode((string) file_get_contents(GOOGLE_FONTS_JSON), true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

/**
 * Membership check used by validation and by buildStyleHeader().
 */
function isGoogleFont(string $family): bool
{
    foreach (getGoogleFonts() as $f) {
        if ($f['family'] === $family) {
            return true;
        }
    }
    return false;
}

/**
 * Build the Google Fonts CSS URL for the chosen family, with sensible
 * weights for the PDF render. Returns null if the family is unknown.
 */
function buildGoogleFontImportUrl(string $family): ?string
{
    foreach (getGoogleFonts() as $f) {
        if ($f['family'] !== $family) {
            continue;
        }
        $picks = pickWeightsForPdf($f['weights']);
        return 'https://fonts.googleapis.com/css2?family='
            . str_replace(' ', '+', $f['family'])
            . ':wght@' . implode(';', $picks)
            . '&display=swap';
    }
    return null;
}

/**
 * Pick a regular + a bold from a font's available weights.
 *
 * Most fonts have 400 and 700 — those are picked directly. For fonts
 * that don't, we pick the closest to 400 for "regular" and the next
 * weight up (preferring 700 > 800 > 600 > 900 > 500) for "bold". For
 * fonts with a single weight (e.g. some display faces), only that one
 * weight is returned.
 *
 * @param int[] $weights
 * @return int[]
 */
function pickWeightsForPdf(array $weights): array
{
    if (empty($weights)) {
        return [400];
    }
    $weights = array_values(array_unique($weights));
    sort($weights);

    if (in_array(400, $weights, true)) {
        $regular = 400;
    } else {
        // Closest to 400
        usort($weights, static fn($a, $b) => abs($a - 400) <=> abs($b - 400));
        $regular = $weights[0];
        sort($weights);
    }

    $bold = null;
    foreach ([700, 800, 600, 900, 500] as $candidate) {
        if (in_array($candidate, $weights, true) && $candidate !== $regular) {
            $bold = $candidate;
            break;
        }
    }

    return $bold !== null ? [$regular, $bold] : [$regular];
}
