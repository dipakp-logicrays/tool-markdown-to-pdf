# Configuration

There are three layers of configuration: `includes/config.php` for PHP-side
defaults and binary paths, `.env` for runtime settings (font source, API
key), and the per-request form fields (which live in `DEFAULTS` and can
be overridden by the user on each conversion).

---

## `includes/config.php` — defaults and binary paths

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

const PAGE_SIZES   = ['A4', 'Letter', 'Legal', 'A3', 'A5'];
const ORIENTATIONS = ['portrait', 'landscape'];
const ALLOWED_EXTS = ['md', 'markdown', 'mdown', 'mkd'];
```

To raise the upload cap above 16 MB, also bump `upload_max_filesize` and
`post_max_size` in `php.ini` (or in `usr/local/etc/php/conf.d/uploads.ini`
for the Docker image).

To swap Chromium for Google Chrome (or vice versa), edit `CHROME_BIN`.

---

## `.env` — runtime settings

The project ships a `.env.example`; copy it to `.env`:

```bash
cp .env.example .env
$EDITOR .env
```

`.env` is **gitignored** and **`.dockerignore`d** — never committed and
never baked into the image. Real environment variables passed via
`docker run -e …` or `docker compose environment:` win over `.env` values,
so the same image can run in multiple deployments with different keys.

### Variables

#### `GOOGLE_FONTS_API_KEY` *(optional)*

Used only by `bin/refresh_fonts` to fetch the latest catalog from the
[Google Fonts Developer API](https://developers.google.com/fonts/docs/developer_api).
The bundled `assets/data/google_fonts.json` already has 1,943 families,
so this is only needed when you want to refresh.

Generate a key:

1. [console.cloud.google.com](https://console.cloud.google.com) →
   APIs & Services → enable **Web Fonts Developer API**
2. Credentials → Create credentials → API key
3. Restrict to *Web Fonts Developer API only*

#### `FONT_SOURCE` *(optional, default `google`)*

Which font catalog drives the dropdown.

| Value | Behavior |
|---|---|
| `google` (default) | Top 20 popular Google Fonts + "More fonts…" modal with all 1,943 families. Live previews lazy-loaded via Google's CDN. Requires outbound network at PDF-render time. |
| `system` | Server's installed fonts via `fc-list`. Live previews served byte-for-byte from disk through `api/font.php`. Works offline. |

Switching is a `.env` edit + page reload — no rebuild.

---

## Per-request options (form fields)

Every option in `DEFAULTS` is also a form field. The user picks values
per conversion; the backend validates against ranges and whitelists in
`includes/validation.php`:

| Field | Validation |
|---|---|
| `page_size` | One of `PAGE_SIZES` |
| `orientation` | One of `ORIENTATIONS` |
| `margin_top` / `margin_right` / `margin_bottom` / `margin_left` | Numeric, 0–60 |
| `font_size` | Numeric, 6–24 |
| `font_family` | Letters/digits/spaces/hyphens/dots only |
| `markdown` (file) | Extension in `ALLOWED_EXTS`, size ≤ `MAX_UPLOAD_MB` |

Invalid input returns HTTP 400 with a JSON `{"error": "..."}` body.

---

## Print CSS — `includes/converter.php::buildStyleHeader()`

The per-request `<style>` block injected into pandoc's HTML output lives
in `buildStyleHeader()`. It uses:

- The form's margins as `@page { margin: … }`
- The form's body font size as the base; inline code, table text, and
  table-code scale relative to it
- The chosen font family (with system-sans fallback stack)
- Heading + code-block + table page-break rules

To customize the look (e.g. add syntax-highlighting colors, change the
heading borders, dark mode), edit this function. It's the only place
the print CSS is defined — all rules in one ~80-line template.

---

## Docker image — environment variables

For Docker deployments, pass settings via `-e` / Compose `environment:`:

```yaml
services:
  app:
    image: md-to-pdf-converter:local
    environment:
      FONT_SOURCE: google
      GOOGLE_FONTS_API_KEY: ${GOOGLE_FONTS_API_KEY:-}
```

The app's `includes/env.php` checks `getenv()` first, so Compose values
always win over any leftover `.env` inside the image (there shouldn't be
one — `.dockerignore` excludes it).

---

Next: [troubleshooting.md](./troubleshooting.md) — common issues and how
to diagnose them.
