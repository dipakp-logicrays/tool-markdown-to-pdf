<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_fonts.php';

function runPandoc(string $mdPath, string $cssPath, string $htmlPath, string $title): void
{
    $cmd = sprintf(
        '%s %s --from gfm --to html5 --standalone --metadata title=%s --include-in-header=%s -o %s 2>&1',
        escapeshellcmd(PANDOC_BIN),
        escapeshellarg($mdPath),
        escapeshellarg($title),
        escapeshellarg($cssPath),
        escapeshellarg($htmlPath)
    );
    exec($cmd, $output, $rc);
    if ($rc !== 0 || !file_exists($htmlPath)) {
        throw new RuntimeException('Pandoc failed: ' . implode("\n", $output));
    }
}

function runChrome(string $htmlPath, string $pdfPath, string $jobDir): void
{
    $userDataDir = $jobDir . '/chrome_profile';
    // --virtual-time-budget gives Chrome up to 5s of virtual time to settle
    // network and rendering, which is what makes web-font fetches (Google Fonts)
    // complete before the PDF is snapshotted.
    $cmd = sprintf(
        '%s --headless --disable-gpu --no-sandbox --hide-scrollbars '
        . '--no-pdf-header-footer --user-data-dir=%s '
        . '--virtual-time-budget=5000 '
        . '--print-to-pdf=%s %s 2>&1',
        escapeshellcmd(CHROME_BIN),
        escapeshellarg($userDataDir),
        escapeshellarg($pdfPath),
        escapeshellarg('file://' . $htmlPath)
    );
    exec($cmd, $output, $rc);
    if (!file_exists($pdfPath)) {
        throw new RuntimeException('Chrome failed to produce a PDF: ' . implode("\n", $output));
    }
}

function streamPdf(string $pdfPath, string $downloadName): void
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'output.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . (string) filesize($pdfPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Access-Control-Expose-Headers: Content-Disposition');
    readfile($pdfPath);
}

function cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
    }
    @rmdir($dir);
}

/**
 * Build the <style> block included into the pandoc HTML output.
 *
 * Font sizes for inline code, table text, and table-code are derived from
 * the body font size so the relationships stay consistent at any scale.
 */
function buildStyleHeader(array $opts): string
{
    $sizeToken = $opts['page_size']
        . ($opts['orientation'] === 'landscape' ? ' landscape' : '');

    $bodyFont  = number_format($opts['font_size'], 2, '.', '');
    $codeFont  = number_format($opts['font_size'] - 1.3, 2, '.', '');
    $tableFont = number_format($opts['font_size'] - 1.7, 2, '.', '');
    $tableCode = number_format($opts['font_size'] - 2.7, 2, '.', '');

    $mt = $opts['margin_top'];
    $mr = $opts['margin_right'];
    $mb = $opts['margin_bottom'];
    $ml = $opts['margin_left'];

    $chosen = $opts['font_family'] ?? '';
    $bodyFamily = $chosen !== ''
        ? '"' . $chosen . '", -apple-system, "Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif'
        : '-apple-system, "Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif';

    // If the chosen font is a Google Font, emit a top-level <link> so
    // headless Chrome treats it as a critical resource and waits for the
    // WOFF2 to arrive before snapshotting. @import inside <style> is not
    // tracked reliably by --print-to-pdf. System fonts resolve via
    // fontconfig on the server and need no link.
    $googleLink = '';
    if ($chosen !== '') {
        $importUrl = buildGoogleFontImportUrl($chosen);
        if ($importUrl !== null) {
            $googleLink = '<link rel="stylesheet" href="' . htmlspecialchars($importUrl, ENT_QUOTES) . '">' . "\n";
        }
    }

    return <<<HTML
{$googleLink}<style>
  @page {
    size: {$sizeToken};
    margin: {$mt}mm {$mr}mm {$mb}mm {$ml}mm;
  }
  html, body {
    font-family: {$bodyFamily};
    font-size: {$bodyFont}pt;
    line-height: 1.5;
    color: #1f2328;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  body { max-width: 100%; margin: 0; padding: 0; }
  h1, h2, h3, h4 { font-weight: 600; line-height: 1.25; margin-top: 1.4em; margin-bottom: 0.5em; page-break-after: avoid; }
  h1 { font-size: 1.9em; border-bottom: 2px solid #d0d7de; padding-bottom: 0.3em; margin-top: 0; }
  h2 { font-size: 1.45em; border-bottom: 1px solid #d0d7de; padding-bottom: 0.25em; }
  h3 { font-size: 1.2em; }
  h4 { font-size: 1.05em; }
  p, ul, ol { margin: 0.5em 0 0.7em 0; }
  ul, ol { padding-left: 1.5em; }
  li { margin: 0.15em 0; }
  blockquote { border-left: 4px solid #d0d7de; color: #57606a; margin: 0.8em 0; padding: 0.2em 0.9em; background: #f6f8fa; }
  blockquote p { margin: 0.3em 0; }
  code {
    font-family: "SFMono-Regular", Menlo, Consolas, "Liberation Mono", monospace;
    font-size: {$codeFont}pt; background: #eff1f3; color: #1f2328;
    padding: 0.1em 0.35em; border-radius: 3px; white-space: nowrap;
  }
  pre {
    background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 4px;
    padding: 0.7em 0.9em; overflow-x: auto;
    font-size: {$codeFont}pt; line-height: 1.45;
    white-space: pre-wrap; word-wrap: break-word;
    /* Allow long code blocks to flow across pages so a tall block at the
     * bottom of a page doesn't push the whole block to the next page and
     * leave a large empty gap. Orphans/widows keeps at least 2 lines
     * together at page boundaries so we don't get a single-line straggler. */
    break-inside: auto;
    page-break-inside: auto;
    orphans: 2;
    widows: 2;
  }
  pre code { background: transparent; padding: 0; font-size: inherit; white-space: pre-wrap; }
  table {
    border-collapse: collapse; width: 100%; margin: 0.8em 0 1em 0;
    page-break-inside: auto; font-size: {$tableFont}pt; table-layout: auto;
  }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; page-break-after: auto; }
  th, td { border: 1px solid #d0d7de; padding: 5px 7px; text-align: left; vertical-align: top; word-wrap: break-word; }
  th { background: #f6f8fa; font-weight: 600; }
  tr:nth-child(2n) td { background: #fbfcfd; }
  td code, th code { font-size: {$tableCode}pt; padding: 0.05em 0.3em; white-space: nowrap; word-break: keep-all; overflow-wrap: normal; }
  hr { border: 0; border-top: 1px solid #d0d7de; margin: 1.4em 0; }
  a { color: #0969da; text-decoration: none; }
  strong { font-weight: 600; }
  h3 + p, h4 + p, h3 + ul, h4 + ul { page-break-before: avoid; }
  header#title-block-header { display: none; }
</style>
HTML;
}
