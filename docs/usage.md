# Usage

## In the browser

Visit `http://<host>/md-to-pdf-converter/` (native install) or
`http://localhost:8080` (Docker). The form has four sections:

| Section | Fields | Default |
|---|---|---|
| **Page** | Page size dropdown (A4 / Letter / Legal / A3 / A5), orientation (Portrait / Landscape) | A4, Portrait |
| **Margins (mm)** | Top, Right, Bottom, Left | 16, 12, 16, 12 |
| **Typography** | Font family (Select2 dropdown), Body font size (pt) | Default sans-serif, 10.5 |
| **File** | Drag-and-drop or click to upload `.md`, `.markdown`, `.mdown`, `.mkd` | — |

Click **Convert to PDF**. An overlay loader appears; when the conversion
finishes (~1–3 seconds) the PDF downloads automatically and a green success
banner appears. On error, a red banner shows the reason.

### Orientation auto-swap

Flipping Portrait ↔ Landscape automatically swaps top/bottom with
left/right margins, so the visual proportions stay correct on the rotated
page. Adjust manually after if you want different margins.

### Font picker — Google mode (`FONT_SOURCE=google`)

The dropdown shows the top 20 most popular Google Fonts. The top entry
**+ More fonts…** opens a Google-Docs-style modal:

- Search bar (debounced filter on family name)
- **Show** filter (All / Sans Serif / Serif / Display / Handwriting / Monospace)
- **Sort** filter (Popularity / Alphabetical)
- **My fonts** sidebar — fonts currently selected for the main dropdown,
  with × to remove
- Checkbox rows — toggle a font into / out of your main-dropdown list
- **Cancel** discards changes · **Done** applies them

Previews load lazily as you scroll. Each font option renders in its own
typeface. See [features.md](./features.md#font-picker--modal-google-mode)
for the lazy-loading mechanism.

### Font picker — System mode (`FONT_SOURCE=system`)

The dropdown shows every font installed on the server (via `fc-list`).
Previews are loaded on-scroll from `api/font.php` which streams the
actual font file off disk with a 1-year cache header. Non-Latin fonts
(e.g. *aakar* Gujarati, *Noto Sans Devanagari*) will show their Latin
labels in fallback because those fonts have no Latin glyphs — that's
the same limitation Google Docs's font modal has.

### Reset

The **Reset** button clears the form back to its defaults.

---

## Output

- File format: PDF v1.4
- Filename: `<original-name-without-extension>.pdf`
  (e.g. `MIGRATION_v3.md` → `MIGRATION_v3.pdf`)
- Returned via `Content-Disposition: attachment` so the browser saves it
- Approximate sizes: ~50 KB per page with the default sans-serif font;
  ~300–600 KB per page when using a Google Font (the font file is
  embedded once)

---

## Run with Docker

The bundled wrapper scripts cover the common workflow so you don't need
to type `docker compose …` directly:

| Command | What it does |
|---|---|
| `./bin/up` | Start the container in the background. Builds on first run. Prints the URL. |
| `./bin/down` | Stop and remove the container (image preserved). Pass `--rmi all` to wipe the image too. |
| `./bin/restart` | Restart the container without rebuilding. |
| `./bin/rebuild` | Rebuild the image and recreate the container. Use after Dockerfile or source changes. |
| `./bin/logs` | Tail container stdout+stderr. Ctrl-C to exit. |
| `./bin/shell` | Open an interactive bash shell inside the container. |
| `./bin/health` | Run `bin/health_check` **inside** the container. |
| `./bin/health_check` | Same script, **host-side** check. Use for non-Docker installs. |
| `./bin/refresh_fonts` | Re-fetch the Google Fonts catalog. Reads `GOOGLE_FONTS_API_KEY` from `.env` or env var. |

Typical session:

```bash
./bin/up               # boot
./bin/health           # confirm
# ... use the converter in your browser ...
./bin/logs             # inspect issues
./bin/down             # done
```

To refresh the font catalog inside the container:

```bash
./bin/shell
# now inside the container
GOOGLE_FONTS_API_KEY=xxx /var/www/html/bin/refresh_fonts
```

Or pass via Docker exec:

```bash
docker compose exec -e GOOGLE_FONTS_API_KEY=xxx app /var/www/html/bin/refresh_fonts
```

---

## API (programmatic use)

The form posts to `api/convert.php`. You can also call it directly:

```bash
curl -X POST -H "X-Requested-With: XMLHttpRequest" \
    -F "page_size=A4" \
    -F "orientation=portrait" \
    -F "margin_top=16" \
    -F "margin_right=12" \
    -F "margin_bottom=16" \
    -F "margin_left=12" \
    -F "font_size=10.5" \
    -F "font_family=Inter" \
    -F "markdown=@INPUT.md" \
    -o OUTPUT.pdf \
    "http://localhost:8080/api/convert.php"
```

Response: `application/pdf` on success (HTTP 200), or `application/json`
with `{"error": "..."}` on failure (HTTP 400 / 500). The
`Content-Disposition` header contains the filename.

---

Next: [features.md](./features.md) · [configuration.md](./configuration.md) ·
[troubleshooting.md](./troubleshooting.md)
