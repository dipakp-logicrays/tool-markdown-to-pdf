<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/config.php';

// Resolve the font source from .env (FONT_SOURCE = google | system).
$fontSource = strtolower((string) envGet('FONT_SOURCE', 'google'));
if (!in_array($fontSource, ['google', 'system'], true)) {
    $fontSource = 'google';
}

if ($fontSource === 'system') {
    require_once __DIR__ . '/includes/fonts.php';
    $systemFonts = getInstalledFonts();
} else {
    require_once __DIR__ . '/includes/google_fonts.php';
    $googleFonts  = getGoogleFonts();
    $popularFonts = array_slice($googleFonts, 0, 20);
}
$d = DEFAULTS;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Markdown → PDF Converter</title>

    <!-- Tailwind (Play CDN) -->
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>

    <!-- Figtree font (UI), matching the laraveldemo look -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">

    <?php if ($fontSource === 'google'): ?>
    <!-- Google Fonts: previews load lazily as options scroll into view (see app.js). -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php endif; ?>

    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

    <!-- App styles -->
    <link href="assets/css/app.css?v=1" rel="stylesheet">

    <style>
        body { font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body class="antialiased bg-gray-100 min-h-screen">

<!-- Page heading -->
<header class="bg-white shadow">
    <div class="max-w-4xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex items-center justify-between">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">Markdown → PDF Converter</h1>
        <span class="hidden sm:inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
            Pandoc + Headless Chrome
        </span>
    </div>
</header>

<main class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <!-- Card: convert form -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <!-- Gradient header strip -->
            <div class="bg-gradient-to-r from-indigo-500 to-purple-600 px-6 py-4">
                <h3 class="text-lg font-semibold text-white flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Convert Markdown to PDF
                </h3>
                <p class="text-indigo-100 text-sm mt-1">Configure the page, choose a font, upload your <code class="bg-white/20 px-1 py-0.5 rounded">.md</code> file.</p>
            </div>

            <!-- Inline alert (hidden by default — populated by JS on error) -->
            <div id="alert" class="hidden mx-6 mt-6 p-4 rounded-md text-sm" role="alert"></div>

            <!-- Form body -->
            <div class="p-6">
                <form id="convert-form" enctype="multipart/form-data" class="space-y-6" novalidate>

                    <!-- Page section -->
                    <fieldset>
                        <legend class="block text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">Page</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="page_size" class="block text-sm font-medium text-gray-700 mb-2">
                                    Page size <span class="text-red-500">*</span>
                                </label>
                                <select id="page_size" name="page_size" class="select2 block w-full" required>
                                    <?php foreach (PAGE_SIZES as $s): ?>
                                        <option value="<?= htmlspecialchars($s) ?>" <?= $s === $d['page_size'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="orientation" class="block text-sm font-medium text-gray-700 mb-2">
                                    Orientation <span class="text-red-500">*</span>
                                </label>
                                <select id="orientation" name="orientation" class="select2 block w-full" required>
                                    <?php foreach (ORIENTATIONS as $o): ?>
                                        <option value="<?= htmlspecialchars($o) ?>" <?= $o === $d['orientation'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($o)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Flipping orientation will swap margins automatically.</p>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Margins section -->
                    <fieldset>
                        <legend class="block text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">Margins (mm)</legend>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <?php foreach (['top','right','bottom','left'] as $side):
                                $key = 'margin_' . $side; ?>
                                <div>
                                    <label for="<?= $key ?>" class="block text-sm font-medium text-gray-700 mb-2">
                                        <?= ucfirst($side) ?>
                                    </label>
                                    <input type="number" step="0.5" min="0" max="60" id="<?= $key ?>" name="<?= $key ?>"
                                           value="<?= htmlspecialchars((string) $d[$key]) ?>"
                                           class="block w-full px-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <!-- Typography section -->
                    <fieldset>
                        <legend class="block text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">Typography</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="font_family" class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-2">
                                    <span>Font family</span>
                                    <?php if ($fontSource === 'google'): ?>
                                        <span title="<?= number_format(count($googleFonts)) ?> Google Fonts available · click 'More fonts…' to browse the full catalog"
                                              class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-indigo-100 text-indigo-700 cursor-help">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                            </svg>
                                            <?= number_format(count($googleFonts)) ?> Google Fonts
                                        </span>
                                    <?php else: ?>
                                        <span title="<?= number_format(count($systemFonts)) ?> system fonts discovered via fc-list on this server"
                                              class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-gray-100 text-gray-700 cursor-help">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                            </svg>
                                            <?= number_format(count($systemFonts)) ?> system fonts
                                        </span>
                                    <?php endif; ?>
                                </label>
                                <select id="font_family" name="font_family" class="select2 block w-full" data-source="<?= htmlspecialchars($fontSource) ?>">
                                    <option value="">— Default sans-serif —</option>
                                    <?php if ($fontSource === 'google'): ?>
                                        <option value="__MORE__" data-action="more">+ More fonts…</option>
                                        <?php foreach ($popularFonts as $gf): ?>
                                            <option value="<?= htmlspecialchars($gf['family']) ?>"
                                                    data-category="<?= htmlspecialchars($gf['category']) ?>">
                                                <?= htmlspecialchars($gf['family']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php foreach ($systemFonts as $f): ?>
                                            <option value="<?= htmlspecialchars($f['family']) ?>"
                                                    data-font-url="api/font.php?family=<?= rawurlencode($f['family']) ?>"
                                                    data-font-format="<?= htmlspecialchars($f['format']) ?>">
                                                <?= htmlspecialchars($f['family']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?php if ($fontSource === 'google'): ?>
                                        Top 20 popular fonts shown. Click <strong>More fonts…</strong> to browse all <?= number_format(count($googleFonts)) ?> Google Fonts.
                                    <?php else: ?>
                                        <?= number_format(count($systemFonts)) ?> system fonts available via fontconfig. Set <code class="bg-gray-100 px-1 rounded">FONT_SOURCE=google</code> in <code class="bg-gray-100 px-1 rounded">.env</code> to switch.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <label for="font_size" class="block text-sm font-medium text-gray-700 mb-2">
                                    Body font size (pt) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" step="0.5" min="6" max="24" id="font_size" name="font_size"
                                       value="<?= htmlspecialchars((string) $d['font_size']) ?>"
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-md leading-5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                                <p class="text-xs text-gray-500 mt-1">Inline code, table text, and table-code scale relative to this.</p>
                            </div>
                        </div>
                    </fieldset>

                    <!-- File section -->
                    <fieldset>
                        <legend class="block text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">File</legend>
                        <label for="markdown" class="block">
                            <div id="dropzone" class="mt-1 flex justify-center px-6 pt-8 pb-8 border-2 border-gray-300 border-dashed rounded-md hover:border-indigo-400 transition-colors cursor-pointer bg-gray-50">
                                <div class="space-y-1 text-center">
                                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <div class="flex text-sm text-gray-600 justify-center">
                                        <span class="font-medium text-indigo-600 hover:text-indigo-500">Upload a markdown file</span>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500">.md, .markdown, .mdown, .mkd · Max <?= MAX_UPLOAD_MB ?> MB</p>
                                    <p id="filename" class="text-sm font-medium text-indigo-700 hidden pt-2"></p>
                                </div>
                            </div>
                            <input id="markdown" name="markdown" type="file" class="sr-only" accept=".md,.markdown,.mdown,.mkd" required>
                        </label>
                    </fieldset>

                    <!-- Footer -->
                    <div class="border-t border-gray-200 pt-6 flex items-center justify-between">
                        <button type="reset" id="reset-btn"
                                class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Reset
                        </button>
                        <button type="submit" id="submit-btn"
                                class="inline-flex items-center px-5 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Convert to PDF
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Help banner -->
        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3 text-sm text-blue-700">
                    Built with PHP + Apache. The conversion pipeline is Pandoc (GFM → HTML5) then headless Chrome (HTML → PDF). See <code class="bg-white/60 px-1 py-0.5 rounded">README.md</code> in this directory for details.
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Loader overlay -->
<div id="loader" class="hidden fixed inset-0 z-50 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-2xl p-8 flex flex-col items-center max-w-sm">
        <svg class="animate-spin h-12 w-12 text-indigo-600 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <p class="text-base font-semibold text-gray-900">Converting…</p>
        <p class="text-sm text-gray-500 mt-1">Pandoc → HTML → PDF</p>
    </div>
</div>

<?php if ($fontSource === 'google'): ?>
<!-- Fonts modal — mirrors Google Docs's font browser:
     left pane is the searchable/filterable font list with checkboxes,
     right pane is the "My fonts" sidebar showing currently-checked fonts.
     Cancel discards the working state; Done applies it to the main dropdown. -->
<div id="font-modal" class="hidden fixed inset-0 z-50 bg-gray-900/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-5xl flex flex-col" style="max-height: 88vh;">
        <!-- Header -->
        <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Fonts</h2>
            <button type="button" id="font-modal-close" class="text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Toolbar: search + filters -->
        <div class="px-6 py-3 border-b border-gray-200 flex flex-wrap items-end gap-3">
            <div class="relative flex-1 min-w-[160px]">
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input id="font-modal-search" type="search" placeholder="Search"
                       class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-[10px] text-gray-500 uppercase tracking-wide mb-1">Show</label>
                <select id="font-modal-show" class="border border-gray-300 rounded-md text-sm py-1.5 px-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">All</option>
                    <option value="sans-serif">Sans Serif</option>
                    <option value="serif">Serif</option>
                    <option value="display">Display</option>
                    <option value="handwriting">Handwriting</option>
                    <option value="monospace">Monospace</option>
                </select>
            </div>
            <div>
                <label class="block text-[10px] text-gray-500 uppercase tracking-wide mb-1">Sort</label>
                <select id="font-modal-sort" class="border border-gray-300 rounded-md text-sm py-1.5 px-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="popularity">Popularity</option>
                    <option value="alphabetical">Alphabetical</option>
                </select>
            </div>
        </div>

        <!-- Body: list + My fonts sidebar -->
        <div class="flex flex-1 min-h-0">
            <!-- Font list (scrollable, infinite scroll) -->
            <div id="font-modal-list" class="flex-1 overflow-y-auto" tabindex="0">
                <div id="font-modal-empty" class="hidden p-8 text-center text-sm text-gray-500">No fonts match your search.</div>
                <div id="font-modal-loading" class="hidden p-8 text-center text-sm text-gray-500">
                    <svg class="animate-spin inline-block w-5 h-5 mr-2 text-indigo-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    Loading…
                </div>
            </div>

            <!-- My fonts sidebar -->
            <aside class="w-64 border-l border-gray-200 bg-gray-50 flex flex-col">
                <div class="px-4 py-3 border-b border-gray-200 text-sm font-medium text-gray-700">My fonts</div>
                <div id="font-modal-myfonts" class="flex-1 overflow-y-auto p-2 space-y-1">
                    <!-- chips appended by app.js -->
                </div>
            </aside>
        </div>

        <!-- Footer -->
        <div class="border-t border-gray-200 px-6 py-3 flex justify-between items-center bg-white rounded-b-xl">
            <p id="font-modal-count" class="text-xs text-gray-500"></p>
            <div class="flex items-center gap-2">
                <button type="button" id="font-modal-cancel"
                        class="px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    Cancel
                </button>
                <button type="button" id="font-modal-done"
                        class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-full focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Done
                </button>
            </div>
        </div>
    </div>
</div>

<?php endif; // end font modal ?>

<!-- jQuery (required by Select2) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- App JS -->
<script src="assets/js/app.js?v=1"></script>
</body>
</html>
