# Features

## Conversion pipeline

**Pandoc (GFM → HTML5)** + **headless Chrome (HTML → PDF)**.

Each request:

1. Creates a per-job temp directory under `tmp/job_<hex>/`.
2. Writes the uploaded `.md` and a per-request CSS `<style>` derived from
   the form options.
3. Runs:
   ```
   pandoc input.md --from gfm --to html5 --standalone \
       --metadata title=<basename> \
       --include-in-header=style.html \
       -o output.html
   ```
4. Renders the HTML with headless Chrome:
   ```
   google-chrome --headless --disable-gpu --no-sandbox \
       --disable-dev-shm-usage --hide-scrollbars --no-pdf-header-footer \
       --user-data-dir=<jobdir>/chrome_profile \
       --virtual-time-budget=5000 \
       --print-to-pdf=output.pdf file://<jobdir>/output.html
   ```
5. Streams the PDF back with `Content-Type: application/pdf` and
   `Content-Disposition: attachment; filename="<basename>.pdf"`.
6. Cleans up the job directory.

`--virtual-time-budget=5000` is the secret sauce that makes web fonts work
in the PDF: Chrome waits up to 5 seconds of virtual time for the
`<link rel="stylesheet" href="https://fonts.googleapis.com/...">` to load
the WOFF2 before snapshotting.

---

## Page controls

- Page size: **A4 / Letter / Legal / A3 / A5**
- Orientation: **Portrait / Landscape**
- Margins: independent **Top / Right / Bottom / Left** in mm (0–60)
- Body font size: 6–24 pt — inline code, table text, and table-code all
  scale proportionally so the typographic hierarchy holds at any size

### Orientation auto-swap

Flipping orientation in the form **automatically swaps top/bottom with
left/right** margins so the visual proportions stay correct on the rotated
page. Portrait `16/12/16/12` → Landscape `12/16/12/16`.

---

## AJAX submit with overlay loader

The form never reloads the page. JavaScript hijacks the submit, posts via
`fetch()` with `X-Requested-With: XMLHttpRequest`, shows an overlay loader
during the conversion, then triggers a blob download programmatically when
the PDF arrives. On error, a red inline banner shows the server's JSON
error message.

---

## Font picker — Google mode (default)

`FONT_SOURCE=google` in `.env`. The dropdown is divided into:

- **Top 20 popular Google Fonts** in the visible dropdown (Roboto, Open
  Sans, Inter, Lato, Montserrat, Poppins, Source Sans 3, …)
- **+ More fonts…** at the top opens a modal browser of **all 1,943 Google
  Fonts**.

### Modal browser

Mirrors Google Docs's "Fonts" feature:

- **Search** bar (debounced 150 ms, case-insensitive substring match)
- **Show** filter — All / Sans Serif / Serif / Display / Handwriting /
  Monospace
- **Sort** — Popularity (default) or Alphabetical
- **My fonts** sidebar — currently-checked fonts, with × to remove
- **Checkbox rows** — toggle a font into / out of your main dropdown
- **Cancel** discards the working state, **Done** applies it
- **Infinite scroll** — 50 rows rendered per page, more appended as you
  near the bottom
- **Esc / backdrop click / × button** close the modal

### Lazy preview loading

The big trick: **previews load only as options scroll into view**.

```
templateResult → renders <span data-font-name="X">X</span>  (no font-family yet)
select2:open  → grabs the picker's results UL via the Select2 instance API
            (avoids the multi-instance class-selector trap)
              → attaches IntersectionObserver (root: viewport,
                respects overflow clipping in the dropdown UL)
  ↓ scroll
option enters viewport → queuePreview("X")
  ↓ batched (200 ms / max 30 fonts per request)
flushPreviewQueue() → <link rel="stylesheet"
                          href="https://fonts.googleapis.com/css2?family=X&family=Y…">
  ↓ link.onload
applyFontToSpans("X") → all data-font-name="X" spans get font-family
```

A `MutationObserver` also catches options that Select2 re-renders when
the user types in the search box, so previews still load on filtered
results.

When a font is picked, the PDF render injects a top-level
`<link rel="stylesheet" href="...?family=Name:wght@400;700">` into the
HTML head so headless Chrome treats it as a critical resource and waits
for the WOFF2 before snapshotting (helped by `--virtual-time-budget`).

---

## Font picker — System mode

`FONT_SOURCE=system` in `.env`. The dropdown shows every font installed
on the server (via `fc-list`). The modal is hidden.

### Server-streamed font previews

Each option carries `data-font-url="api/font.php?family=…"` and
`data-font-format="truetype|opentype|woff|woff2|collection"`. As an
option scrolls into view, an `@font-face` declaration is injected with
that URL, the browser fetches the binary, applies `font-display: swap`,
and the option's name re-renders in the real font.

### Security of `api/font.php`

Two-layer validation:

1. The requested `family` must exist in the catalog returned by
   `getInstalledFonts()` (which itself only enumerates what `fc-list`
   reports — can't fabricate an arbitrary file path).
2. The resolved path (via `realpath`, so symlinks can't escape) must
   start with one of the whitelisted prefixes:
   - `/usr/share/fonts`
   - `/usr/local/share/fonts`
   - `/var/lib/fonts`
   - `/home`

Path traversal attempts return 404. Responses include
`Cache-Control: public, max-age=31536000, immutable` so each font is
fetched at most once per browser cache lifetime.

### Variant selection

When a family has multiple files (e.g., `Ubuntu-R.ttf`, `Ubuntu-B.ttf`,
`Ubuntu-Th.ttf`, `Ubuntu-RI.ttf`), a small ranking heuristic picks the
**Regular** face for preview:

- Bonus for `Regular` / `Roman` / `Normal` / `Book` / `Latin` / `-R` /
  `-Rg` markers
- Heavy penalty for `italic` / `oblique` / `-RI` / `-BI` / `-LI` etc.
- Penalty for weight modifiers (Hair, Thin, Light, Medium, Bold, Black,
  Heavy) — both full words and 2-letter abbreviations (`-Th`, `-Bd`, …)
- Penalty for width modifiers (Condensed, Extended, Narrow, Wide)
- Filename length as final tie-breaker

### Where the fonts come from

`getInstalledFonts()` calls `fc-list : family file` and explicitly sets
`HOME` to the calling user's home directory. Without this, Apache's PHP
runs with an empty `$HOME` and fontconfig silently skips
`~/.local/share/fonts/` — making any user-installed fonts invisible.
With the fix, user-dir fonts show up correctly.

---

## Typography

The print-CSS template in `includes/converter.php::buildStyleHeader()`
defines:

- Headings (h1–h4) with `page-break-after: avoid` so a heading never
  ends a page with no content
- Code blocks (`<pre>`) that **flow across pages** — no large empty
  gaps when a long code block doesn't fit at the bottom of a page
- `orphans: 2; widows: 2` so at least 2 lines stay together at page
  boundaries
- Tables with **repeated headers** across page breaks and
  `page-break-inside: avoid` on individual rows
- Inline `<code>` in tables locked to **single-line at smaller font**
  — identifiers like `AuthorizedWithCapture` or `GetExemptCertificates`
  never break mid-character
- Light/dark-friendly default colors (gray-100 / gray-900) for printing

---

## Health check

`bin/health_check` (or `bin/health` for Docker) verifies the runtime end
to end:

```
— Runtime          → PHP, exec(), Apache running
— Conversion tools → pandoc, chromium/google-chrome, fc-list + count
— Project state    → tmp/ writable, every project file present, catalog size
— Configuration    → .env presence, GOOGLE_FONTS_API_KEY set, FONT_SOURCE valid
— Network          → fonts.googleapis.com reachable
```

Exit 0 if all checks pass, 1 if any fail. `--quiet` mode for CI.

---

## Docker image

- Base: `php:8.4-apache`
- Includes: Pandoc 3.x, Chromium 148, fontconfig, Latin font pack
  (Liberation, DejaVu, Noto Core, FiraCode, JetBrains Mono, Cascadia Code)
- Healthcheck pings `http://localhost/` every 30 s
- 7 wrapper scripts (`bin/up`, `bin/down`, `bin/restart`, `bin/rebuild`,
  `bin/logs`, `bin/shell`, `bin/health`) for one-word ops
- Image size ~1.5 GB (chromium is most of it)
- `.env` not baked into the image — runtime env vars take precedence

See [installation.md](./installation.md) for setup.

---

## Refresh the Google Fonts catalog

`bin/refresh_fonts` fetches the latest catalog from the
[Google Fonts Developer API](https://developers.google.com/fonts/docs/developer_api),
strips italic variants to keep the file small, preserves popularity
order, and writes the result to `assets/data/google_fonts.json`.

No restart needed — the converter re-reads the JSON on every form load.

---

Next: [configuration.md](./configuration.md) ·
[troubleshooting.md](./troubleshooting.md)
