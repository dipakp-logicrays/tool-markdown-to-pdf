# Installation

Pick one of two paths:

- **[Option 1 — Docker](#option-1--docker)** *(recommended; one command,
  the image bundles every runtime dependency)*
- **[Option 2 — Manual LAMP install](#option-2--manual-lamp-install)**
  *(install the L/A/P stack + Pandoc + Chrome + fontconfig directly on
  the host)*

Either way, [verify](#verify-the-install) the install with
`./bin/health_check` when you're done.

---

## Option 1 — Docker

### Requirements

- Docker Engine 20.10+
- Docker Compose v2 (the modern `docker compose ...` command, not the
  legacy `docker-compose` binary)

Verify:

```bash
docker --version          # → Docker version 20.10+
docker compose version    # → Docker Compose version v2.x
```

> **⚠ This project has no published Docker image yet.** Nothing has been
> pushed to Docker Hub or another registry. The first `./bin/up` builds
> the image **locally from the `Dockerfile` in this repo** — expect
> ~2 minutes on a fresh checkout while apt fetches Pandoc, Chromium, and
> the font pack. Subsequent runs reuse the cached image and start in
> seconds. **No manual `docker build` step is required** — `./bin/up`
> wraps it.

### First-time setup *(only do this once per checkout)*

```bash
# 1. Clone the repo
git clone <repo-url> md-to-pdf-converter
cd md-to-pdf-converter

# 2. (optional) Add a Google Fonts API key. The app works without it —
#    .env is only needed if you want to later refresh the bundled font
#    catalog via ./bin/refresh_fonts.
cp .env.example .env
$EDITOR .env

# 3. Start it. This implicitly builds the image on first run, then
#    starts the container. Idempotent — safe to run again later.
./bin/up
```

When ready, `./bin/up` prints something like:

```
Starting container...
 Container md-to-pdf-converter Started

Ready at: http://localhost:8080
Tail logs: ./bin/logs
Stop:      ./bin/down
```

Open `http://localhost:8080` and upload a `.md` file.

### Day-to-day use

After the first build, the image is cached locally. Daily workflow is just:

```bash
./bin/up        # boot it up
./bin/down      # stop and remove the container when done
```

`./bin/up` is **idempotent** — running it twice when the container is
already up is a safe no-op. `./bin/down` is similarly safe to call when
nothing is running.

### Hot-reload is enabled by default

`docker-compose.yml` **bind-mounts the project source** into the container,
so editing `index.php`, `includes/*.php`, `api/*.php`, `assets/css/app.css`,
or `assets/js/app.js` on the host is **immediately visible** in the
container on the next browser refresh — no restart, no rebuild. PHP
doesn't need a compile step, and Apache reads the file off the
bind-mounted filesystem on every request.

`tmp/` is overlaid with a Docker anonymous volume so the container's
per-job working directories don't pollute your working copy on the host.

If you want a production-style self-contained image (no bind-mount,
source baked in via `COPY`), either build and run with `docker run`
directly against the image, or create a `docker-compose.prod.yml` that
overrides the `volumes:` block.

### When to use which script

| Situation | Command |
|---|---|
| First time (or after pulling new changes) | `./bin/up` |
| You edited `.php` / `.js` / `.css` / `.json` | **nothing** — just refresh the browser |
| You changed an env var or `.env` | `./bin/restart` (Apache caches env on boot) |
| You edited `Dockerfile` or added apt packages | `./bin/rebuild` |
| You want to see the error from a failed conversion | `./bin/logs` |
| You want to poke around inside the container | `./bin/shell` |
| You want to verify the deployed environment is healthy | `./bin/health` |
| You want to refresh the Google Fonts catalog | `./bin/shell` then `/var/www/html/bin/refresh_fonts` |
| You want to nuke everything and start fresh | `./bin/down --rmi all && ./bin/up` |

### What you get

A single container with:

- PHP 8.4 + Apache 2.4 (`php:8.4-apache` base, Debian Trixie)
- Pandoc 3.1
- Chromium 148 (symlinked to `/usr/bin/google-chrome` so the app finds it)
- Fontconfig + a Latin font pack (Liberation, DejaVu, Noto Core,
  FiraCode, JetBrains Mono, Cascadia Code)
- All app files baked in, with `tmp/` writable by `www-data`
- A healthcheck pinging `http://localhost/` every 30 s

Image size on disk: ~1.5 GB (Chromium is the bulk of it).

### Running raw Docker commands *(advanced — not needed)*

If you'd rather see exactly what the wrappers do, or you can't / don't
want to use the scripts:

```bash
docker compose up -d           # what ./bin/up runs
docker compose down            # what ./bin/down runs
docker compose restart         # what ./bin/restart runs
docker compose up -d --build   # what ./bin/rebuild runs
docker compose logs -f         # what ./bin/logs runs
docker compose exec app bash   # what ./bin/shell runs
```

Or skip Compose entirely with plain `docker`:

```bash
# Manual build + run, no compose file
docker build -t md-to-pdf-converter:local .
docker run -d --name md-to-pdf -p 8080:80 \
    -e FONT_SOURCE=google \
    -e GOOGLE_FONTS_API_KEY=$GOOGLE_FONTS_API_KEY \
    md-to-pdf-converter:local
```

### Environment variables

Pass `FONT_SOURCE` and `GOOGLE_FONTS_API_KEY` at runtime via Compose's
`environment:` or `docker run -e`. They override anything in `.env`,
which makes it easy to ship the same image to multiple environments.

```yaml
# docker-compose.yml
services:
  app:
    environment:
      FONT_SOURCE: google
      GOOGLE_FONTS_API_KEY: ${GOOGLE_FONTS_API_KEY:-}
```

### Stop / clean up

```bash
./bin/down                 # stop + remove container, keep image
./bin/down --rmi all       # also delete the image
```

---

## Option 2 — Manual LAMP install

The app is **L**inux + **A**pache + **P**HP (no MySQL — there's no
database). Plus three extras the conversion pipeline needs:

- `pandoc` — Markdown → HTML
- `google-chrome` (or `chromium`) — HTML → PDF via `--print-to-pdf`
- `fontconfig` (provides `fc-list`) — system font discovery

### 2.1 Web server: Apache + mod_php

**Ubuntu / Debian:**

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-cli
```

**macOS (Homebrew):**

```bash
brew install httpd php
brew services start httpd
```

Verify:

```bash
apache2 -v 2>/dev/null || httpd -v        # Apache 2.4.x
php -v                                     # PHP 8.x
```

### 2.2 PHP — verify `exec()` is enabled

The app shells out to `pandoc` and `google-chrome` via PHP's `exec()`.
Confirm `exec` isn't on the `disable_functions` list:

```bash
php -r "echo function_exists('exec') ? 'OK' : 'BLOCKED';"
```

If it prints `BLOCKED`, edit `/etc/php/8.x/apache2/php.ini` and remove
`exec` from `disable_functions`, then restart Apache.

### 2.3 Pandoc

**Ubuntu / Debian:**

```bash
sudo apt install -y pandoc
```

**macOS:**

```bash
brew install pandoc
```

Verify:

```bash
pandoc --version | head -1     # pandoc 2.9 or higher
```

Other platforms: see the
[Pandoc install guide](https://pandoc.org/installing.html).

### 2.4 Google Chrome (or Chromium)

**Ubuntu / Debian (Google Chrome stable):**

```bash
wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | \
    sudo gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg
echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" | \
    sudo tee /etc/apt/sources.list.d/google-chrome.list
sudo apt update
sudo apt install -y google-chrome-stable
```

**Ubuntu / Debian (Chromium alternative — smaller, available without
adding Google's repo):**

```bash
sudo apt install -y chromium
sudo ln -sf /usr/bin/chromium /usr/bin/google-chrome
```

The symlink lets `includes/config.php`'s default `CHROME_BIN` keep
working. Alternatively edit that constant directly.

**macOS:**

```bash
brew install --cask google-chrome
```

Verify:

```bash
google-chrome --version        # Google Chrome 100+ or Chromium 100+
```

### 2.5 Fontconfig

**Ubuntu / Debian:**

```bash
sudo apt install -y fontconfig
```

**macOS:**

```bash
brew install fontconfig
```

Verify:

```bash
fc-list : family | wc -l       # any non-zero number; usually 200+
```

### 2.6 A reasonable starter font pack *(optional)*

Useful for both `FONT_SOURCE=system` (the dropdown shows these directly)
and as fallback fonts when Google Fonts aren't reachable.

**Ubuntu / Debian:**

```bash
sudo apt install -y \
    fonts-liberation \
    fonts-dejavu \
    fonts-noto-core \
    fonts-firacode \
    fonts-jetbrains-mono \
    fonts-cascadia-code
```

### 2.7 `curl` *(optional)*

Used by `bin/health_check` to verify that `fonts.googleapis.com` is
reachable. The Docker image bundles it; on a manual install just:

```bash
sudo apt install -y curl     # Debian/Ubuntu
brew install curl            # macOS (usually pre-installed)
```

### 2.8 Drop in the code

The app lives under Apache's docroot — typically `/var/www/html/` on
Linux. Drop the repo there:

```bash
cd /var/www/html/
git clone <repo-url> md-to-pdf-converter
cd md-to-pdf-converter
```

It's now served at `http://<host>/md-to-pdf-converter/`. No Apache vhost
edits needed.

### 2.9 Permissions

`tmp/` must be writable by the user Apache runs as. On Debian/Ubuntu
that's usually `www-data`:

```bash
# Find Apache's user
ps -ef | grep -E "apache2|httpd" | head -1
# Then:
sudo chown -R www-data:www-data tmp/    # adjust user as needed
sudo chmod 0775 tmp/
```

If Apache runs as **your** user (common in dev setups), just make sure
`tmp/` is owned by you:

```bash
sudo chown -R $(whoami):$(whoami) tmp/
chmod 0775 tmp/
```

### 2.10 `.env` *(optional)*

The bundled `assets/data/google_fonts.json` already has 1,943 Google
Fonts, so the app works out of the box. `.env` is only needed if you
want to:

- **Refresh** the catalog via `./bin/refresh_fonts` (requires a Google
  Fonts Developer API key)
- **Switch** the dropdown to system fonts (`FONT_SOURCE=system`)

```bash
cp .env.example .env
$EDITOR .env
```

```env
GOOGLE_FONTS_API_KEY=your_api_key_here
FONT_SOURCE=google        # or 'system'
```

API key: [console.cloud.google.com](https://console.cloud.google.com)
→ APIs & Services → enable **Web Fonts Developer API** → Credentials →
Create credentials → API key → restrict to *Web Fonts Developer API only*.

`.env` is gitignored.

---

## Verify the install

Whichever path you took, run the health check:

```bash
./bin/health_check        # native install (host-side check)
./bin/health              # Docker install (runs inside the container)
```

Expected output for a clean install (Docker example):

```
Markdown → PDF Converter — Health Check
— Runtime
  ✓ PHP available — 8.4.x
  ✓ PHP exec() is enabled
  ✓ Apache is running
— Conversion tools
  ✓ pandoc available — pandoc 3.x
  ✓ Chrome available — Chromium 148.x (or Google Chrome 148.x)
  ✓ fc-list available — N font families installed
— Project state
  ✓ tmp/ exists and is writable
  ✓ <every project file present>
  ✓ Google Fonts catalog: 1943 families
— Configuration
  ✓ .env present and has GOOGLE_FONTS_API_KEY set      (or a single ! warning if no .env)
  ✓ FONT_SOURCE=google (effective)
— Network (Google Fonts)
  ✓ fonts.googleapis.com reachable
————————————————
OK — N checks passed, 0 failures.
```

Then visit the URL printed by `./bin/up` (Docker, default
`http://localhost:8080`) or `http://<host>/md-to-pdf-converter/` (native
install), and upload a `.md` file to convert.

If a check fails: see [troubleshooting.md](./troubleshooting.md) —
the most common issues (Pandoc errors, Chrome sandbox/perm errors, font
discovery problems) are covered there with reproduction commands.

---

Next: [usage.md](./usage.md) for using the form ·
[features.md](./features.md) for the feature deep-dive ·
[configuration.md](./configuration.md) for tuning.
