# Troubleshooting

Run **`./bin/health_check`** first (or `./bin/health` for Docker) — it
checks every runtime dependency, file path, and the Google Fonts network
reachability in one shot. Most setup problems show up there immediately.

---

## Pandoc failed

> `Pandoc failed: <stderr output>`

The conversion ran but `pandoc` exited non-zero. To reproduce manually
and see the full diagnostic:

```bash
cd tmp/job_<hex>/
/usr/bin/pandoc input.md --from gfm --to html5 --standalone \
    --metadata title=test --include-in-header=style.html -o output.html
```

Common causes:

- **Markdown contains a Pandoc extension that GFM mode doesn't support.**
  Switch the `--from` value in `includes/converter.php::runPandoc()` to
  `markdown_strict` or vanilla `markdown` to test.
- **Pandoc 3.x parses some old GFM constructs differently** from
  Pandoc 2.x. The Docker image ships 3.1, the host install often ships
  2.9. If output differs between host and Docker, this is usually why.

---

## Chrome / Chromium failed to produce a PDF

> `Chrome failed to produce a PDF: <stderr output>`

The conversion ran pandoc → HTML successfully but Chrome crashed.
Reproduce:

```bash
cd tmp/job_<hex>/
/usr/bin/google-chrome --headless --disable-gpu --no-sandbox \
    --disable-dev-shm-usage --hide-scrollbars --no-pdf-header-footer \
    --user-data-dir=$(pwd)/chrome_profile --virtual-time-budget=5000 \
    --print-to-pdf=output.pdf file://$(pwd)/output.html
```

Common causes:

- **Apache user can't launch Chrome.** Confirm with
  `sudo -u www-data google-chrome --version`. On most distros, the
  default profile dir is read-only for `www-data` — that's why we set
  `--user-data-dir=<jobdir>/chrome_profile` per request.
- **Docker `/dev/shm` too small.** Already handled by
  `--disable-dev-shm-usage`. If you removed it, Chrome will OOM on
  larger documents.
- **Sandbox refusing to launch.** In containers, the default is
  `--no-sandbox` (already set). On hardened hosts, this may be a SELinux
  or AppArmor block — check `journalctl -t audit` for denials.

---

## Font preview not loading in the dropdown

Open browser DevTools → Network tab → filter to `font`. Then open the
font dropdown.

- **In `FONT_SOURCE=google` mode**, you should see
  `fonts.googleapis.com/css2?family=…` requests as you scroll. If none
  fire, check the Console for JavaScript errors (most likely a Select2
  initialization issue).
- **In `FONT_SOURCE=system` mode**, you should see
  `api/font.php?family=…` requests. If they 404, check the
  `data-font-url` attribute on each `<option>` — it must match a font
  the server actually serves. Run `./bin/health_check` to confirm
  `fc-list` works.

If previews fire but text still looks unchanged, the chosen font likely
**lacks Latin glyphs**. Non-Latin fonts (Gujarati, Bengali, Devanagari,
CJK) will display their Latin labels in the system fallback font. Try
scrolling to a known-Latin font like *Atlassian Mono* or *Cascadia Code*
to confirm the pipeline is working.

---

## `api/font.php` returns 404 for fonts I know are installed

Apache typically runs with `$HOME` unset, which makes `fc-list` skip
user-installed fonts in `~/.local/share/fonts/`. The fix is already in
`includes/fonts.php::getInstalledFonts()` — it explicitly sets `HOME` to
the effective user's home directory before invoking `fc-list`.

If you still see 404s:

1. Confirm the font is in `fc-list` output: `fc-list : family file | grep -i 'Family Name'`
2. Confirm the resolved path is under one of the whitelisted prefixes in
   `includes/fonts.php::FONT_DIR_WHITELIST`. If your fonts live elsewhere
   (e.g. `/opt/fonts`), add the prefix to the whitelist.
3. Confirm the Apache user has read permission on the font file.

---

## Permission denied writing `tmp/`

The per-job working directories live under `tmp/`. The user Apache runs
as must own (or be able to write to) this directory.

```bash
# Find Apache's user
ps -ef | grep apache | head -1
# Fix permissions
sudo chown -R <apache-user>:<apache-group> tmp/
sudo chmod 0775 tmp/
```

In Docker, this is set in the Dockerfile (`chown -R www-data:www-data tmp/`)
and shouldn't need manual fixing.

---

## Font picker dropdown is empty

The catalog file might be missing or unreadable:

```bash
ls -l assets/data/google_fonts.json   # should be ~140 KB
php -r 'echo count(json_decode(file_get_contents("assets/data/google_fonts.json"), true));'
```

Should print `1943`. If it prints `0` or the file is missing:

```bash
./bin/refresh_fonts
```

(Requires `GOOGLE_FONTS_API_KEY` in `.env`.)

---

## `bin/refresh_fonts` fails with HTTP 400

> `Google Fonts API returned HTTP 400`

Almost always means the API key is invalid, restricted to the wrong API,
or has billing not enabled. Verify in
[Google Cloud Console](https://console.cloud.google.com) → APIs & Services
→ Credentials:

1. The key exists and isn't deleted
2. Under "Restrict key" → "API restrictions", **Web Fonts Developer API**
   is enabled
3. The API itself is enabled under "Enabled APIs & Services"

---

## Conversion is slow

A cold conversion is typically 2–4 seconds end-to-end:

- ~50 ms pandoc
- ~2–3 s Chrome cold start
- ~200 ms WOFF2 download (when using a Google Font)
- ~100 ms PDF stream back

If conversions are consistently > 10 s:

- Check `dmesg` for OOM kills (Chrome is memory-hungry; under 1 GB
  available may swap heavily)
- In Docker, `docker stats` while a conversion runs
- Chrome with `--virtual-time-budget=5000` will wait up to 5 s for
  network resources to settle — if a slow font CDN is involved this
  can dominate. To diagnose, temporarily lower the budget in
  `includes/converter.php::runChrome()` and watch what happens

---

## Docker container won't start

```bash
./bin/logs    # see Apache startup messages
docker compose ps   # check container state
docker compose down && docker compose up --build   # full rebuild
```

If `./bin/up` reports "container is unhealthy", wait 30 s for the
healthcheck to run, then `./bin/logs` for the error. Most often it's a
PHP fatal error from a syntax mistake in a recently-edited include file.

---

## Health check passes but the form is blank

Browser cache. Hard refresh (Ctrl-Shift-R / Cmd-Shift-R) or
disable cache in DevTools → Network. The page itself uses CDN assets
(Tailwind, Select2, jQuery) — a network problem reaching those would
also produce a blank or unstyled page.

Confirm: `curl -sS http://localhost:8080/ | head -20` should return
HTML starting with `<!DOCTYPE html>`.

---

## More

If something's not covered here:

1. Run `./bin/health_check` and paste the output
2. Run `./bin/logs` (Docker) or `tail -50 /var/log/apache2/error.log`
   (native install) and look for the most recent error
3. Open browser DevTools → Console + Network tabs while reproducing
