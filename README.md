# Markdown → PDF Converter

A small PHP web app that converts a Markdown file to a styled PDF.
Pipeline: **Pandoc (GFM → HTML5)** + **headless Chrome (HTML → PDF)**.

The UI mirrors the card layout used in the laraveldemo contact form
(Tailwind CDN, indigo / gradient header, Select2 dropdowns, AJAX submit
with overlay loader).

---

## Features

- **Page**: A4 / Letter / Legal / A3 / A5, Portrait / Landscape
- **Margins**: Top / Right / Bottom / Left in mm
  - Flipping orientation auto-swaps top/bottom with left/right
- **Typography**:
  - Body font size in pt; inline code, table text, and table-code scale relative to body
  - **Font family picker** — configurable via `FONT_SOURCE` in `.env`:
    - **`FONT_SOURCE=google`** (default): top 20 popular Google Fonts + a Google Docs-style "More fonts…" modal that browses all ~1,900 families with search, category/sort filters, checkboxes, and a "My fonts" sidebar. Previews load lazily via `IntersectionObserver`, batched 30 fonts per Google Fonts CSS request. When picked, the PDF render injects a `<link rel="stylesheet" href="...?family=Name:wght@400;700">` and headless Chrome fetches the WOFF2 before snapshotting (helped by `--virtual-time-budget=5000`). Requires outbound network at PDF-render time.
    - **`FONT_SOURCE=system`**: flat dropdown of the server's installed fonts (`fc-list`). Works fully offline. No live previews (the user's browser may not share fonts with the server, but the PDF render uses the server's installed copy via fontconfig).
    - The font-count badge next to the field reflects whichever source is active and is computed dynamically at every page load.
- **File upload**: `.md` / `.markdown` / `.mdown` / `.mkd`, max 16 MB
- **Drag-and-drop** file picker
- **AJAX submit** with overlay loader — no page redirect; PDF downloads as a Blob
- **Identifier-friendly table styling** — inline code in tables never breaks mid-character

---

## Requirements

| Component | Purpose | Verified version | Project / docs |
|---|---|---|---|
| PHP 8.x | Web layer | 8.4 | [php.net](https://www.php.net/manual/en/install.php) |
| Apache HTTPD | HTTP server (serves the docroot) | 2.x | [httpd.apache.org install guide](https://httpd.apache.org/docs/2.4/install.html) |
| `pandoc` | Markdown → HTML | 2.9 | [pandoc.org/installing.html](https://pandoc.org/installing.html) |
| `google-chrome` | HTML → PDF (`--print-to-pdf`) | 148.x | [google.com/chrome](https://www.google.com/chrome/) |
| `fc-list` (fontconfig) | Discover system-installed font families | from `fontconfig` | [fontconfig project](https://www.freedesktop.org/wiki/Software/fontconfig/) |
| `curl` (optional) | Used by `bin/health_check` to ping Google Fonts | any | [curl.se](https://curl.se/) |

PHP needs the `exec()` function available (no `disable_functions` blocking it).

### Install dependencies

**Ubuntu / Debian**

```bash
# PHP + Apache + mod_php + pandoc + fontconfig
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-cli pandoc fontconfig curl

# Google Chrome (stable)
wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | sudo gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg
echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" | \
    sudo tee /etc/apt/sources.list.d/google-chrome.list
sudo apt update
sudo apt install -y google-chrome-stable

# A reasonable starter set of system fonts (optional but recommended)
sudo apt install -y fonts-liberation fonts-firacode fonts-noto fonts-jetbrains-mono
```

**macOS (Homebrew)**

```bash
brew install httpd php pandoc fontconfig curl
brew install --cask google-chrome
```

**Verifying the install:** run `./bin/health_check` from the project root — it checks every dependency is present, reachable, and that the project files are in place.

---

## Install

The project lives at `/var/www/html/md-to-pdf-converter/`, which is
under Apache's docroot — so it's served at `http://<host>/md-to-pdf-converter/`
with no extra config.

```
md-to-pdf-converter/
├── index.php                # form view
├── api/
│   └── convert.php          # AJAX endpoint: POST → PDF or JSON error
├── includes/
│   ├── config.php           # constants / defaults
│   ├── env.php              # minimal .env loader (FONT_SOURCE, GOOGLE_FONTS_API_KEY)
│   ├── fonts.php            # system font discovery via fc-list (used when FONT_SOURCE=system)
│   ├── google_fonts.php     # reads assets/data/google_fonts.json + builds CSS URLs
│   ├── validation.php       # input validation
│   └── converter.php        # pandoc + chrome + cleanup + CSS builder (with Google <link>)
├── assets/
│   ├── css/app.css          # custom Select2 + dropzone + alert styles
│   ├── js/app.js            # Select2 + lazy font preview loader + AJAX submit
│   └── data/
│       └── google_fonts.json  # bundled snapshot (1900+ families × weights, ~140 KB)
├── assets/
│   ├── css/app.css          # custom Select2 + dropzone + alert styles
│   └── js/app.js            # Select2 init, AJAX submit, loader, on-change
├── tmp/                     # per-job working dirs (auto-created, auto-cleaned)
├── bin/
│   ├── health_check         # bash script — verifies every runtime dependency
│   └── refresh_fonts        # bash script — re-fetches Google Fonts catalog → JSON
├── .gitignore               # ignores tmp/* (per-job working dirs) + editor noise
└── README.md
```

CDN dependencies (loaded by `index.php`):

- Tailwind CSS (Play CDN, v3.4.16)
- jQuery 3.7.1 (Select2 prerequisite)
- Select2 4.1.0-rc.0
- Figtree font from `fonts.bunny.net`

No `npm install` / `composer install` required.

---

## Use

1. Visit `http://<host>/md-to-pdf-converter/`
2. Configure page size, orientation, margins, font, body font size
3. Drag-drop or pick a `.md` file
4. Click **Convert to PDF** — a loader appears, the PDF downloads when ready

Typical conversion takes ~3–4 seconds end-to-end (Chrome cold start dominates).

---

## How it works

`assets/js/app.js`:

1. Intercepts form submit; sends `multipart/form-data` to `api/convert.php` via `fetch()` with `X-Requested-With: XMLHttpRequest`.
2. Shows the overlay loader.
3. On `200` + `Content-Type: application/pdf`, reads the response as a Blob, parses the filename from `Content-Disposition`, and triggers a programmatic download.
4. On any non-PDF response, parses the JSON `{error: "..."}` body and shows an inline alert.

`api/convert.php`:

1. Validates the form options against the allowed ranges and the file against extension/size.
2. Creates a per-request job directory in `tmp/` (random 8-byte hex name).
3. Writes the uploaded markdown, a per-request CSS `<style>` header derived from form options, then runs:
   ```
   pandoc input.md --from gfm --to html5 --standalone \
     --metadata title=<basename> \
     --include-in-header=style.html \
     -o output.html
   ```
4. Renders the HTML with headless Chrome:
   ```
   google-chrome --headless --disable-gpu --no-sandbox \
     --hide-scrollbars --no-pdf-header-footer \
     --user-data-dir=<jobdir>/chrome_profile \
     --print-to-pdf=output.pdf file://<jobdir>/output.html
   ```
5. Streams the PDF back with `Content-Type: application/pdf` and
   `Content-Disposition: attachment; filename="<basename>.pdf"`, then
   cleans up the job directory.

`includes/converter.php::buildStyleHeader()` is where the print CSS
lives. Body font size drives the relative sizing of inline code, table
text, and table-code so the whole document scales together.

---

## Configuration

All defaults and limits are in `includes/config.php`:

```php
const PANDOC_BIN    = '/usr/bin/pandoc';
const CHROME_BIN    = '/usr/bin/google-chrome';
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
```

To raise the upload cap above 16 MB, also bump `upload_max_filesize`
and `post_max_size` in `php.ini`.

---

## Refreshing the Google Fonts catalog

The font dropdown is populated from a static JSON snapshot at
`assets/data/google_fonts.json`. To pull in newly-released Google Fonts
or pick up weight changes for existing ones, run:

```bash
./bin/refresh_fonts
```

This fetches the latest catalog from the [Google Fonts Developer API](https://developers.google.com/fonts/docs/developer_api),
strips italic variants to keep the file small (the script preserves the
API's popularity ordering), and writes the result to
`assets/data/google_fonts.json`.

No restart needed — the converter re-reads the JSON on every form load.

### Setup the API key

The script reads a Google Fonts Developer API key from a `.env` file in
the project root (or from the `GOOGLE_FONTS_API_KEY` environment
variable). `.env` is gitignored.

```bash
cp .env.example .env
# then edit .env and paste your key
```

To generate a key:

1. [Google Cloud Console](https://console.cloud.google.com) → APIs & Services
2. Enable the **Web Fonts Developer API**
3. Credentials → Create credentials → API key
4. Restrict the key to *Web Fonts Developer API only* — this lets you commit confidently knowing the key has read-only catalog access

## Health check

`bin/health_check` is a small bash script that verifies every runtime
dependency. Run it from the project root to confirm a fresh install or
diagnose a misconfigured box:

```bash
./bin/health_check
```

It checks: PHP + `exec()`, Apache (binary + running), `pandoc`,
`google-chrome` (or `chromium` fallback), `fc-list` + font count, the
`tmp/` directory permissions, that every project file is in place, and
network reachability to `fonts.googleapis.com`.

Exit code is `0` if all checks pass, `1` if any fail. Warnings (e.g.
Apache not running) don't fail the script. Add `--quiet` to print only
failures — useful for CI / monitoring.

## Troubleshooting

- **Run `./bin/health_check` first** — it pinpoints most common setup
  problems (missing tools, wrong paths, permissions).
- **`Pandoc failed: ...`** — Run the pandoc command from the error
  manually in `tmp/job_xxx/` to see the full diagnostic.
- **`Chrome failed to produce a PDF`** — Check Chrome can launch headless
  as the Apache user (`sudo -u <apache-user> google-chrome --headless --dump-dom https://example.com`).
- **Dropdown empty** for fonts — confirm `fc-list` is on `$PATH`
  (`which fc-list`) and `exec()` isn't disabled in PHP (`php -i | grep disable_functions`).
- **Permission denied writing `tmp/`** — make sure the directory is
  writable by the user Apache runs as: `chmod 0775 tmp/ && chown <user>:<group> tmp/`.

---

## Future work / ideas

- Switch to **puppeteer (Node)** with a long-lived Chrome instance if
  throughput becomes important — drops conversion time from ~3s to ~300ms.
- Optional **dark-theme PDF** preset.
- **Cover page** option (title, author, date).
- **Code-block syntax highlighting** via Pandoc's `--highlight-style`.
