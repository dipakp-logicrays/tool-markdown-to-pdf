/* eslint-env browser, jquery */
/* global $, jQuery */

/**
 * Markdown → PDF converter — client-side script.
 *
 * Responsibilities:
 *   - Initialize Select2 on every dropdown.
 *   - Lazy-load Google Font previews as their option scrolls into view in
 *     the font dropdown. Font requests are batched (up to 30 per CSS call,
 *     flushed every 200 ms) so opening the dropdown costs ~one network
 *     round-trip per visible page even with 1900+ fonts in the catalog.
 *   - On orientation change, swap top/bottom with left/right margins.
 *   - File picker UX: drag-and-drop, show selected filename.
 *   - Submit via fetch(), show overlay loader, download blob on success,
 *     show inline alert on error.
 */

(function ($) {
    'use strict';

    const ENDPOINT             = 'api/convert.php';
    const CATALOG_URL          = 'assets/data/google_fonts.json';
    const PREVIEW_BATCH_DELAY  = 200; // ms — collect queue then flush
    const PREVIEW_BATCH_MAX    = 30;  // max fonts per Google Fonts CSS request
    const MODAL_PAGE_SIZE      = 50;  // rows rendered per infinite-scroll page

    // Module state — font preview loader
    const previewQueue       = new Set(); // fonts pending load
    const previewLoaded      = new Set(); // fonts whose CSS has already been fetched
    let   previewFlushTimer  = null;
    let   previewObserver    = null;

    // Module state — fonts modal
    let   modalCatalog        = null;     // all fonts from JSON
    let   modalView           = null;     // current filtered/sorted view, or null for full
    let   modalRendered       = 0;        // count of rows currently in DOM
    let   modalObserver       = null;     // intersection observer for modal rows
    let   modalLastSelectVal  = '';       // Select2 value before "More fonts…" was picked
    let   modalCheckedFonts   = new Set();// working set of fonts in the user's list
    let   modalOriginalChecked = new Set();// snapshot at open time (for Cancel)
    let   modalSearchQuery    = '';
    let   modalShowFilter     = '';
    let   modalSortBy         = 'popularity';

    $(function () {
        initSelect2();
        bindOrientationSwap();
        bindFilePicker();
        bindFormSubmit();
        bindReset();
        bindFontModal();
    });

    // -----------------------------------------------------------------
    // Select2
    // -----------------------------------------------------------------

    function initSelect2() {
        $('select.select2').each(function () {
            const $sel         = $(this);
            const isFontPicker = $sel.attr('id') === 'font_family';
            // Mode comes from PHP via the select's data-source attribute
            // (set by FONT_SOURCE in .env). 'google' uses fonts.googleapis.com
            // for previews; 'system' streams local font files via api/font.php.
            const fontMode = isFontPicker ? (($sel.attr('data-source') || 'google')) : null;

            const config = {
                width: '100%',
                minimumResultsForSearch: isFontPicker ? 0 : Infinity,
                dropdownParent: $sel.parent(),
            };
            if (isFontPicker) {
                config.templateResult    = renderFontOption;
                config.templateSelection = renderFontSelection;
            }
            const $select = $sel.select2(config);

            if (isFontPicker) {
                modalLastSelectVal = $select.val() || '';
                $select.on('select2:selecting', function () {
                    modalLastSelectVal = $(this).val() || '';
                });
                if (fontMode === 'google') {
                    $select.on('select2:select', function (e) {
                        if (e.params.data.id === '__MORE__') {
                            $(this).val(modalLastSelectVal).trigger('change.select2');
                            openFontModal();
                        }
                    });
                }
                $select.on('select2:open',  function () {
                    const inst = $(this).data('select2');
                    setTimeout(function () { setupPreviewObserver(inst); }, 0);
                });
                $select.on('select2:close', teardownPreviewObserver);
            }
        });
    }

    /**
     * Render a single option inside the dropdown. We render the name in a
     * span tagged with data-font-name so the lazy loader can find it later
     * and apply font-family once the corresponding CSS has loaded.
     */
    function renderFontOption(state) {
        if (!state.id) {
            return state.text;
        }
        // "More fonts…" gets a distinct look so it reads as an action, not a font.
        if (state.id === '__MORE__') {
            return $(
                '<span class="font-semibold text-indigo-600 flex items-center">'
                + '<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                +   '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>'
                + '</svg>'
                + 'More fonts…'
                + '</span>'
            );
        }
        const $span = $('<span></span>')
            .attr('data-font-name', state.text)
            .text(state.text)
            .css({ fontSize: '15px', lineHeight: '1.4' });
        // font-family is intentionally NOT set here — it's applied once
        // the Google Fonts CSS for this family has arrived.
        return $span;
    }

    /**
     * Render the selected pill. The user just picked this font, so it's
     * worth queueing its preview load immediately so the pill renders in
     * the chosen face (rather than the default sans).
     */
    function renderFontSelection(state) {
        if (!state.id || state.id === '__MORE__') {
            return state.text;
        }
        const $span = $('<span></span>')
            .attr('data-font-name', state.text)
            .text(state.text)
            .css('font-family', '"' + state.text + '", sans-serif');
        queuePreview(state.text);
        return $span;
    }

    // -----------------------------------------------------------------
    // Lazy font preview loader
    // -----------------------------------------------------------------

    function setupPreviewObserver(select2Inst) {
        // Reach into Select2's own instance to grab THIS picker's results
        // container directly — no class-selector guesswork.
        // (Select2 keeps multiple `.select2-results__options` elements in
        // the DOM across instances, so class-based selectors were grabbing
        // the wrong one.)
        const $results = select2Inst && select2Inst.results && select2Inst.results.$results;
        if (!$results || !$results.length) {
            return;
        }

        // No `root` specified — the browser uses the viewport and respects
        // ancestor overflow clipping (which is what Select2's dropdown uses
        // for its scrollable list). This sidesteps the "is this UL or its
        // parent the scroll container?" question entirely.
        previewObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                const $name = $(entry.target).find('[data-font-name]').first();
                const name  = $name.attr('data-font-name');
                if (name) {
                    queuePreview(name);
                }
            });
        }, { rootMargin: '100px' });

        $results.find('li.select2-results__option').each(function () {
            previewObserver.observe(this);
        });

        // Re-observe newly-rendered options as the user types into the search box.
        // Select2 swaps the contents of .select2-results__options on every search,
        // so the original li elements may be detached. A MutationObserver on the
        // results container catches the new ones.
        const mutObserver = new MutationObserver(function () {
            $results.find('li.select2-results__option').each(function () {
                previewObserver.observe(this);
            });
        });
        mutObserver.observe($results[0], { childList: true, subtree: true });
        previewObserver._mutObserver = mutObserver;
    }

    function teardownPreviewObserver() {
        if (previewObserver) {
            if (previewObserver._mutObserver) {
                previewObserver._mutObserver.disconnect();
            }
            previewObserver.disconnect();
            previewObserver = null;
        }
    }

    function queuePreview(name) {
        if (previewLoaded.has(name)) {
            applyFontToSpans(name);
            return;
        }
        // Pick the right loader based on the current font picker mode.
        const mode = ($('#font_family').attr('data-source') || 'google');
        if (mode === 'system') {
            loadSystemFontPreview(name);
            return;
        }
        previewQueue.add(name);
        if (!previewFlushTimer) {
            previewFlushTimer = setTimeout(flushPreviewQueue, PREVIEW_BATCH_DELAY);
        }
    }

    function flushPreviewQueue() {
        previewFlushTimer = null;
        if (previewQueue.size === 0) {
            return;
        }

        const batch = [];
        previewQueue.forEach(function (name) {
            if (batch.length < PREVIEW_BATCH_MAX && !previewLoaded.has(name)) {
                batch.push(name);
            }
        });

        if (batch.length === 0) {
            previewQueue.clear();
            return;
        }

        const families = batch.map(function (n) {
            return 'family=' + encodeURIComponent(n).replace(/%20/g, '+') + ':wght@400';
        });
        const url = 'https://fonts.googleapis.com/css2?'
            + families.join('&')
            + '&display=swap';

        const link = document.createElement('link');
        link.rel  = 'stylesheet';
        link.href = url;

        link.onload = function () {
            batch.forEach(function (name) {
                previewLoaded.add(name);
                previewQueue.delete(name);
                applyFontToSpans(name);
            });
            if (previewQueue.size > 0) {
                previewFlushTimer = setTimeout(flushPreviewQueue, PREVIEW_BATCH_DELAY);
            }
        };

        link.onerror = function () {
            batch.forEach(function (name) {
                previewQueue.delete(name);
            });
            if (previewQueue.size > 0) {
                previewFlushTimer = setTimeout(flushPreviewQueue, PREVIEW_BATCH_DELAY);
            }
        };

        document.head.appendChild(link);
    }

    /**
     * Inject an @font-face for a system font and apply it to dropdown rows.
     *
     * The URL points at api/font.php on this server, which streams the font
     * file off disk with a 1-year immutable cache. No batching needed since
     * each font is its own HTTP request; the browser's normal connection
     * pool pipelines them as the user scrolls.
     */
    function loadSystemFontPreview(name) {
        if (previewLoaded.has(name)) {
            applyFontToSpans(name);
            return;
        }
        // Mark loaded eagerly so we don't double-inject on rapid intersections.
        previewLoaded.add(name);

        const $opt = $('#font_family option').filter(function () {
            return this.value === name;
        }).first();
        if ($opt.length === 0) return;

        const url    = $opt.attr('data-font-url');
        const format = $opt.attr('data-font-format') || 'truetype';
        if (!url) return;

        let styleEl = document.getElementById('system-font-faces');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'system-font-faces';
            document.head.appendChild(styleEl);
        }
        styleEl.appendChild(document.createTextNode(
            '@font-face { font-family: "' + name + '"; '
            + 'src: url("' + url + '") format("' + format + '"); '
            + 'font-display: swap; }\n'
        ));

        // Apply font-family right away — font-display:swap means the browser
        // will swap glyphs in once the WOFF/TTF arrives.
        applyFontToSpans(name);
    }

    function applyFontToSpans(name) {
        $('[data-font-name="' + cssEscape(name) + '"]')
            .css('font-family', '"' + name + '", sans-serif');
    }

    function cssEscape(s) {
        if (typeof CSS !== 'undefined' && CSS.escape) {
            return CSS.escape(s);
        }
        return s.replace(/(["\\])/g, '\\$1');
    }

    // -----------------------------------------------------------------
    // Fonts modal — "More fonts…" browser with search + infinite scroll
    // -----------------------------------------------------------------

    function bindFontModal() {
        // In system-font mode the modal isn't rendered at all — skip all wiring.
        if (!document.getElementById('font-modal')) {
            return;
        }
        $('#font-modal-close, #font-modal-cancel').on('click', cancelFontModal);
        $('#font-modal-done').on('click', applyFontModal);
        $('#font-modal').on('click', function (e) {
            if (e.target === this) cancelFontModal();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$('#font-modal').hasClass('hidden')) {
                cancelFontModal();
            }
        });

        // Search — debounced
        let searchTimer = null;
        $('#font-modal-search').on('input', function () {
            modalSearchQuery = $(this).val().trim().toLowerCase();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(refreshModalView, 150);
        });

        $('#font-modal-show').on('change', function () {
            modalShowFilter = $(this).val();
            refreshModalView();
        });

        $('#font-modal-sort').on('change', function () {
            modalSortBy = $(this).val();
            refreshModalView();
        });

        // Infinite scroll
        $('#font-modal-list').on('scroll', function () {
            const el = this;
            const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 300;
            if (!nearBottom) return;
            if (modalView && modalRendered < modalView.length) {
                renderModalPage(false);
            }
        });

        // Row click → toggle checkbox
        $('#font-modal-list').on('click', '.modal-font-row', function () {
            const name = $(this).attr('data-font-name');
            if (name) toggleModalChecked(name);
        });

        // Sidebar chip × → uncheck
        $('#font-modal-myfonts').on('click', '.myfont-chip button', function () {
            const name = $(this).closest('.myfont-chip').attr('data-font-name');
            if (name) toggleModalChecked(name, false);
        });
    }

    async function openFontModal() {
        const $modal = $('#font-modal');
        $modal.removeClass('hidden');
        $('body').css('overflow', 'hidden');

        // Snapshot the current main-dropdown options (real fonts only — skip
        // the meta entries) so Cancel can restore exactly this state.
        modalOriginalChecked = new Set();
        $('#font_family option').each(function () {
            const v = this.value;
            if (v && v !== '__MORE__') modalOriginalChecked.add(v);
        });
        modalCheckedFonts = new Set(modalOriginalChecked);

        $('#font-modal-search').val('');
        $('#font-modal-show').val('');
        $('#font-modal-sort').val('popularity');
        modalSearchQuery = '';
        modalShowFilter = '';
        modalSortBy = 'popularity';

        if (modalCatalog === null) {
            $('#font-modal-loading').removeClass('hidden');
            try {
                const r = await fetch(CATALOG_URL, { cache: 'force-cache' });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                modalCatalog = await r.json();
            } catch (err) {
                $('#font-modal-loading').addClass('hidden');
                $('#font-modal-empty').text('Failed to load fonts catalog: ' + err.message).removeClass('hidden');
                return;
            }
            $('#font-modal-loading').addClass('hidden');
        }

        refreshModalView();
        renderMyFontsSidebar();
        setupModalObserver();
        setTimeout(function () { $('#font-modal-search').trigger('focus'); }, 0);
    }

    function cancelFontModal() {
        // Discard working state — restoration happens implicitly because we
        // never wrote modalCheckedFonts back to the Select2 yet.
        modalCheckedFonts = new Set(modalOriginalChecked);
        hideFontModal();
    }

    function applyFontModal() {
        const $sel = $('#font_family');
        const currentVal = $sel.val();

        // Rebuild the options list: keep Default + More, then the checked
        // fonts ordered by popularity (matches modalCatalog order).
        const $default = $sel.find('option[value=""]').detach();
        const $more    = $sel.find('option[value="__MORE__"]').detach();
        $sel.empty().append($default).append($more);

        const ordered = (modalCatalog || []).filter(function (f) {
            return modalCheckedFonts.has(f.family);
        });
        // Any checked names not in the catalog (shouldn't happen, but defensive)
        modalCheckedFonts.forEach(function (name) {
            if (!ordered.find(function (f) { return f.family === name; })) {
                ordered.push({ family: name, category: '' });
            }
        });

        ordered.forEach(function (f) {
            const opt = new Option(f.family, f.family, false, false);
            $sel.append(opt);
        });

        // If the previously-selected font is no longer checked, fall back to Default.
        const stillThere = $sel.find('option').filter(function () {
            return this.value === currentVal;
        }).length > 0;
        const nextVal = stillThere ? currentVal : '';
        $sel.val(nextVal).trigger('change');
        modalLastSelectVal = nextVal;

        hideFontModal();
    }

    function hideFontModal() {
        $('#font-modal').addClass('hidden');
        $('body').css('overflow', '');
        teardownModalObserver();
    }

    function toggleModalChecked(name, forceState) {
        const willCheck = (typeof forceState === 'boolean')
            ? forceState
            : !modalCheckedFonts.has(name);
        if (willCheck) {
            modalCheckedFonts.add(name);
        } else {
            modalCheckedFonts.delete(name);
        }
        // Update any visible rows for this font
        $('#font-modal-list .modal-font-row[data-font-name="' + cssEscape(name) + '"]')
            .toggleClass('is-checked', willCheck);
        renderMyFontsSidebar();
    }

    function renderMyFontsSidebar() {
        const $sb = $('#font-modal-myfonts');
        $sb.empty();
        if (modalCheckedFonts.size === 0) {
            $sb.append('<p class="text-xs text-gray-500 px-2 py-3">No fonts yet. Check fonts on the left to add them.</p>');
            return;
        }
        // Render in popularity order (matches modalCatalog order)
        const inOrder = (modalCatalog || []).filter(function (f) {
            return modalCheckedFonts.has(f.family);
        });
        // Any extras not in catalog
        modalCheckedFonts.forEach(function (name) {
            if (!inOrder.find(function (f) { return f.family === name; })) {
                inOrder.push({ family: name });
            }
        });

        const frag = document.createDocumentFragment();
        inOrder.forEach(function (f) {
            const chip = document.createElement('div');
            chip.className = 'myfont-chip';
            chip.setAttribute('data-font-name', f.family);
            chip.innerHTML =
                '<span class="label"></span>'
                + '<button type="button" title="Remove">'
                +   '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                +     '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>'
                +   '</svg>'
                + '</button>';
            chip.querySelector('.label').textContent = f.family;
            frag.appendChild(chip);
        });
        $sb[0].appendChild(frag);
    }

    function refreshModalView() {
        if (!modalCatalog) return;

        let view = modalCatalog;
        if (modalShowFilter) {
            view = view.filter(function (f) { return f.category === modalShowFilter; });
        }
        if (modalSearchQuery) {
            view = view.filter(function (f) {
                return f.family.toLowerCase().indexOf(modalSearchQuery) !== -1;
            });
        }
        if (modalSortBy === 'alphabetical') {
            view = view.slice().sort(function (a, b) {
                return a.family.localeCompare(b.family);
            });
        }
        modalView = view;
        renderModalPage(true);
    }

    function renderModalPage(reset) {
        const $list = $('#font-modal-list');
        const $empty = $('#font-modal-empty');
        if (reset) {
            $list.find('.modal-font-row').remove();
            modalRendered = 0;
            $list.scrollTop(0);
        }
        const source = modalView || [];
        if (source.length === 0) {
            $empty.removeClass('hidden');
            $('#font-modal-count').text('0 fonts');
            return;
        }
        $empty.addClass('hidden');

        const end = Math.min(modalRendered + MODAL_PAGE_SIZE, source.length);
        const frag = document.createDocumentFragment();
        for (let i = modalRendered; i < end; i++) {
            const f = source[i];
            const row = document.createElement('div');
            row.className = 'modal-font-row';
            row.setAttribute('data-font-name', f.family);
            if (modalCheckedFonts.has(f.family)) {
                row.classList.add('is-checked');
            }
            row.innerHTML =
                '<span class="checkbox">'
                +   '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                +     '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>'
                +   '</svg>'
                + '</span>'
                + '<span class="name"></span>'
                + '<span class="category"></span>';
            row.querySelector('.name').textContent = f.family;
            row.querySelector('.category').textContent = f.category || '';
            frag.appendChild(row);
        }
        $list.find('#font-modal-empty, #font-modal-loading').before(frag);
        modalRendered = end;

        $('#font-modal-count').text(
            (modalSearchQuery || modalShowFilter)
                ? (source.length.toLocaleString() + ' matches of ' + modalCatalog.length.toLocaleString())
                : (modalCatalog.length.toLocaleString() + ' fonts')
        );

        if (modalObserver) {
            $list.find('.modal-font-row:not([data-observed="1"])').each(function () {
                this.setAttribute('data-observed', '1');
                modalObserver.observe(this);
            });
        }
    }

    function setupModalObserver() {
        const rootEl = document.getElementById('font-modal-list');
        if (!rootEl) return;
        modalObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                const name = entry.target.getAttribute('data-font-name');
                if (!name) return;
                queuePreview(name);
                const $name = $(entry.target).find('.name');
                $name.attr('data-font-name', name);
                if (previewLoaded.has(name)) {
                    $name.css('font-family', '"' + name + '", sans-serif');
                }
            });
        }, { root: rootEl, rootMargin: '200px' });

        $('#font-modal-list .modal-font-row').each(function () {
            this.setAttribute('data-observed', '1');
            modalObserver.observe(this);
        });
    }

    function teardownModalObserver() {
        if (modalObserver) {
            modalObserver.disconnect();
            modalObserver = null;
        }
        $('#font-modal-list .modal-font-row').removeAttr('data-observed');
    }

    // -----------------------------------------------------------------
    // Orientation flip — swap (top, bottom) with (left, right)
    // -----------------------------------------------------------------

    function bindOrientationSwap() {
        let previousOrientation = $('#orientation').val();
        $('#orientation').on('change', function () {
            const next = $(this).val();
            if (next === previousOrientation) {
                return;
            }
            const $top    = $('#margin_top');
            const $right  = $('#margin_right');
            const $bottom = $('#margin_bottom');
            const $left   = $('#margin_left');

            const oldTop    = $top.val();
            const oldRight  = $right.val();
            const oldBottom = $bottom.val();
            const oldLeft   = $left.val();

            $top.val(oldLeft);
            $bottom.val(oldRight);
            $left.val(oldTop);
            $right.val(oldBottom);

            previousOrientation = next;
        });
    }

    // -----------------------------------------------------------------
    // File picker
    // -----------------------------------------------------------------

    function bindFilePicker() {
        const $input    = $('#markdown');
        const $dropzone = $('#dropzone');
        const $filename = $('#filename');

        $input.on('change', function () {
            const file = this.files && this.files[0];
            if (file) {
                $filename.text(file.name).removeClass('hidden');
            } else {
                $filename.addClass('hidden').text('');
            }
        });

        ['dragenter', 'dragover'].forEach(function (evt) {
            $dropzone.on(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                $dropzone.addClass('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            $dropzone.on(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                $dropzone.removeClass('is-dragover');
            });
        });
        $dropzone.on('drop', function (e) {
            const files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
            if (files && files.length > 0) {
                $input[0].files = files;
                $input.trigger('change');
            }
        });
    }

    // -----------------------------------------------------------------
    // AJAX submit + loader
    // -----------------------------------------------------------------

    function bindFormSubmit() {
        $('#convert-form').on('submit', async function (e) {
            e.preventDefault();
            hideAlert();

            const fileInput = document.getElementById('markdown');
            if (!fileInput.files || fileInput.files.length === 0) {
                showAlert('error', 'Please choose a markdown file to convert.');
                return;
            }

            showLoader(true);
            const $submit = $('#submit-btn').prop('disabled', true);

            try {
                const formData = new FormData(this);
                const response = await fetch(ENDPOINT, {
                    method:  'POST',
                    body:    formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                const contentType = response.headers.get('Content-Type') || '';
                if (!response.ok || !contentType.includes('pdf')) {
                    let msg = 'Conversion failed.';
                    try {
                        const data = await response.json();
                        if (data && data.error) {
                            msg = data.error;
                        }
                    } catch (_) { /* keep default */ }
                    showAlert('error', msg);
                    return;
                }

                const blob     = await response.blob();
                const filename = parseDownloadFilename(response.headers.get('Content-Disposition'))
                    || (fileInput.files[0].name.replace(/\.[^.]+$/, '') + '.pdf');

                triggerDownload(blob, filename);
                showAlert('success', 'PDF generated: ' + filename);
            } catch (err) {
                showAlert(
                    'error',
                    'Network or server error: ' + (err && err.message ? err.message : err)
                );
            } finally {
                showLoader(false);
                $submit.prop('disabled', false);
            }
        });
    }

    function bindReset() {
        $('#reset-btn').on('click', function () {
            setTimeout(function () {
                $('#convert-form select.select2').trigger('change.select2');
                $('#filename').addClass('hidden').text('');
                hideAlert();
            }, 0);
        });
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    function showLoader(visible) {
        $('#loader').toggleClass('hidden', !visible);
    }

    function showAlert(kind, message) {
        $('#alert')
            .removeClass('hidden alert-error alert-success')
            .addClass(kind === 'error' ? 'alert-error' : 'alert-success')
            .text(message);
    }

    function hideAlert() {
        $('#alert').addClass('hidden').removeClass('alert-error alert-success').text('');
    }

    function parseDownloadFilename(headerValue) {
        if (!headerValue) {
            return null;
        }
        const m = /filename="?([^"]+)"?/i.exec(headerValue);
        return m ? m[1] : null;
    }

    function triggerDownload(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
})(jQuery);
