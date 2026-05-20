# Deploy to Render.com — Step-by-step guide

Host this converter on the public web for free, with auto-deploy on
every `git push`. No credit card required.

**Total time:** ~10 minutes (most of which is Render building the image
the first time).

---

## What you need before you start

- A **GitHub account** with this repo pushed to it
- A web browser (you'll do everything in the Render dashboard)
- *(Optional)* A **Google Fonts API key** if you'll want to refresh the
  font catalog from Render later. The bundled catalog has 1,943 fonts so
  this is optional.

---

## Step 1 — Push the code to GitHub *(skip if already done)*

```bash
cd /var/www/html/md-to-pdf-converter

# If you used `gh` CLI:
gh repo create tool-markdown-to-pdf --public --source . --push

# Or with plain git:
git remote add origin git@github.com:<your-username>/tool-markdown-to-pdf.git
git push -u origin main
```

Confirm in your browser that the repo shows `Dockerfile`, `render.yaml`,
and the rest of the files.

---

## Step 2 — Create a Render account

1. Open **https://render.com** in a browser
2. Click **Get Started for Free** (top-right)
3. Click **GitHub** to sign up via OAuth — this also lets Render see
   your repos in one step
4. Authorize Render to access your account

No credit card is asked for the free plan.

---

## Step 3 — Connect Render to your GitHub repo

This authorization happens automatically the first time you create a
Blueprint, but you can do it manually if you prefer:

1. In Render's dashboard, top-right avatar → **Account Settings**
2. Click **GitHub** in the left sidebar
3. Click **Configure** next to your GitHub username
4. Choose **All repositories** (easier) or **Only select repositories**
   and pick `tool-markdown-to-pdf`
5. Click **Save**

---

## Step 4 — Deploy from the Blueprint

This is where Render reads `render.yaml` from your repo and sets
everything up automatically.

1. Top-right of the dashboard → click the **+ New** button
2. Choose **Blueprint** from the dropdown *(not "Web Service")*
3. Pick your **`tool-markdown-to-pdf`** repo from the list
4. *(If asked)* select the branch — typically `main`
5. Render scans the repo and shows a preview of what it will create:
   - **1 Web Service**: `md-to-pdf-converter`
   - **Runtime**: Docker
   - **Plan**: Free
   - **Region**: Oregon
6. **Service Group Name**: type any name (e.g. `md-to-pdf`). This groups
   your services under one Blueprint — affects the dashboard URL only.
7. You'll be prompted for **environment variables marked `sync: false`**
   in the blueprint:
   - **`GOOGLE_FONTS_API_KEY`** — paste your Google Fonts Developer API
     key here, or leave it blank if you don't have one yet.
8. Click **Apply** (or **Deploy Blueprint**)

Render kicks off the first build.

---

## Step 5 — Watch the build

Render takes you to the service's page. The **Logs** tab shows the
build in real time. Expected sequence:

```
==> Cloning from https://github.com/<you>/tool-markdown-to-pdf...
==> Using Dockerfile at ./Dockerfile
==> Building image (this takes a few minutes)...
   [build output: apt-get install pandoc chromium fontconfig ...]
==> Pushing image to registry...
==> Starting service...
   [Apache startup messages]
==> Your service is live 🎉
```

**First build typically takes 5–8 minutes** (downloading the base image
+ apt-installing Pandoc, Chromium, fonts). Subsequent builds after a
push are 2–4 minutes thanks to Docker layer caching.

If the build fails, scroll up in the logs to find the error and check
[Troubleshooting](#troubleshooting) below.

---

## Step 6 — Test it

Once the **Status** badge at the top says **Live**, click the URL Render
shows:

```
https://md-to-pdf-converter.onrender.com
```

(Yours may have a hash suffix the first time — `md-to-pdf-converter-XXXX.onrender.com`.)

You should see the same form you see locally. Try uploading a `.md` file
and converting it.

---

## Step 7 — Auto-deploy on push *(already on)*

Already enabled via `autoDeploy: true` in `render.yaml`. From now on:

```bash
git add .
git commit -m "your changes"
git push origin main
```

→ Render detects the push, pulls the latest commit, rebuilds the image,
and redeploys. You can watch each deploy in the **Deploys** tab.

### Verify the GitHub webhook actually got installed

Render's auto-deploy depends on a webhook GitHub fires on every push.
The Blueprint setup *should* install this webhook automatically — but
on some accounts (especially organization repos with restricted GitHub
App permissions) it silently fails to install, and pushes then don't
trigger anything.

To verify:

1. Open `https://github.com/<your-username>/<your-repo>/settings/hooks`
2. You should see **one** webhook with:
   - **Payload URL**: `https://api.render.com/deploy/srv-<your-service-id>?key=...`
   - A green ✓ on **Recent Deliveries** (most recent should be a successful 200)
3. If there's no webhook, or the most recent delivery shows red ✗, see
   [Troubleshooting → Auto-deploy isn't firing](#auto-deploy-isnt-firing-after-git-push).

---

## After deploy

### View / edit environment variables later

Service page → **Environment** tab:

- Add, edit, or remove env vars
- Changes trigger a new deploy automatically

### Open a shell inside the running container

Service page → **Shell** tab → click **Launch Shell**. Useful for:

```bash
# Refresh the Google Fonts catalog
/var/www/html/bin/refresh_fonts

# Verify the deployed environment
/var/www/html/bin/health_check

# Inspect logs / files
ls /var/www/html/tmp/
```

### Add a custom domain

Service page → **Settings** → **Custom Domains** → **+ Add Custom Domain**:

1. Type your domain (e.g. `md.example.com`)
2. Render shows the CNAME target — point your DNS at it
3. SSL is set up automatically once DNS propagates (~5 minutes to 1 hour)

Free on the free plan.

### View metrics

Service page → **Metrics** tab. Free plan shows last 24 hours of CPU,
RAM, and response time.

---

## Free-plan limits to keep in mind

| Limit | Free plan |
|---|---|
| RAM | 512 MB |
| CPU | 0.1 vCPU (shared) |
| Service compute hours | 750 / month (≈ 1 service always-on) |
| Outbound bandwidth | 100 GB / month |
| Builds | Unlimited |
| Custom domains | ✅ unlimited |
| HTTPS / SSL | ✅ free, auto-renewed |
| **Auto-sleep when idle** | **After 15 min of no requests** |
| **Cold-start time** | **30–60 seconds on the next request after sleep** |

### Keeping the service warm — avoid the cold-start delay

A GitHub Actions workflow at
[`.github/workflows/keep-warm.yml`](.github/workflows/keep-warm.yml)
pings the deployed URL every 5 minutes so Render never reaches the
15-minute idle window that triggers spin-down. Result: no 30-60s cold
start on the first request after a quiet period.

It activates automatically once the file is on `main` — the first run
fires on the next cron tick (within ~5 min) or you can trigger it
manually from the **Actions** tab → "Keep Render service warm" →
**Run workflow**.

**If your service URL is different** from the fallback baked into the
workflow, override it without editing the YAML:

1. GitHub repo → **Settings** → **Secrets and variables** → **Actions**
2. **Variables** tab → **New repository variable**
3. Name: `RENDER_URL`, Value: your service URL (e.g.
   `https://md-to-pdf-converter-xyz.onrender.com`)
4. Save. Next scheduled run picks it up.

**Trade-off to be aware of:** keeping the service awake 24/7 burns
roughly **720–744 hrs/month** of Render's **750 hrs free compute**
quota — fine for one service, but if you also run other free Render
services in the same workspace the totals add up and the service may
pause when quota runs out. For high-traffic / production-style use,
the **Starter plan ($7/mo)** removes the quota and the sleep entirely.

### Auto-sleep — what it means in practice

Your service shuts down after 15 minutes of no traffic. The next visitor
sees a 30–60 second loading screen while the container restarts. After
that first request, it's fast again. For a personal tool this is fine;
for production use upgrade to **Starter ($7/mo)** which removes the sleep.

### 512 MB RAM — what to watch for

Chromium plus PHP plus Apache fit in ~400 MB at idle. **Very large
documents (50+ pages, lots of fonts, big tables)** can OOM the free
plan. If you hit it, either:

- Switch `FONT_SOURCE=system` (skips downloading Google Fonts at render
  time — saves ~50 MB)
- Upgrade to **Starter** for 512 MB → 2 GB

---

## Troubleshooting

### Auto-deploy isn't firing after `git push`

Symptom: you push to `main`, but Render's **Events** tab shows nothing
new. Auto-Deploy is set to "On Commit" in Render's Settings, but
nothing happens.

This means GitHub isn't telling Render about your pushes — the webhook
that bridges them is either missing or broken. Two ways to fix:

#### Option A — Re-link GitHub (cleanest)

In Render dashboard → service → **Settings** → scroll to **GitHub**
section:

1. Click **Disconnect** and confirm
2. Click **Connect** and re-authorize Render's GitHub access
3. Pick the repo again — this reinstalls the webhook with full payload
   signing

Future pushes should auto-deploy normally. If your repo is in a
GitHub Organization with restricted app permissions, an org admin may
need to approve the Render GitHub App first
(`https://github.com/organizations/<org>/settings/installations`).

#### Option B — Add a webhook manually using the Deploy Hook

When Option A doesn't work (or the org won't grant the Render app
broader access), install the webhook yourself using Render's
**Deploy Hook** URL — a private URL that triggers a deploy on any POST.

1. In Render → **Settings** → **Build & Deploy** section → find
   **Deploy Hook** at the bottom → click the eye icon to reveal,
   then copy the URL. It looks like:
   `https://api.render.com/deploy/srv-XXXXX?key=YYYYY`
2. On GitHub: open `https://github.com/<you>/<repo>/settings/hooks`
3. Click **Add webhook**
4. Fill in:

   | Field | Value |
   |---|---|
   | Payload URL | the Deploy Hook URL from step 1 |
   | Content type | `application/x-www-form-urlencoded` *(Render ignores the body — any content type works)* |
   | Secret | leave empty *(the `?key=` already authenticates)* |
   | SSL verification | Enable |
   | Which events | **Just the `push` event** |
   | Active | ✓ checked |

5. Click **Add webhook**

GitHub immediately fires a `ping` to test the URL, which Render treats
as a deploy trigger. You should see "First deploy started" in Render's
Events tab within ~30 seconds. Every future `git push` does the same.

> ⚠️ **The Deploy Hook URL is a secret.** It contains a key that lets
> anyone trigger a deploy of your service. Don't paste it in chat,
> screenshots, gists, or commits. If you ever leak it: Render →
> **Settings** → **Build & Deploy** → **Deploy Hook** →
> **Regenerate hook**, then update the GitHub webhook with the new URL.

### Build fails partway through `apt-get install`

Render's free build infrastructure sometimes throttles big builds.
Click **Manual Deploy** (top right of the service page) → **Deploy
latest commit** to retry. The cache helps.

### `502 Bad Gateway` after "deploy live"

Check the **Logs** tab for Apache startup errors. Common causes:

- **Apache didn't bind to `$PORT`.** The `docker/entrypoint.sh` script
  in this repo handles that — confirm Render is using the right
  `Dockerfile`. The startup log should NOT include "Listen 80" if
  Render set `PORT=10000`.
- **Healthcheck timed out.** The `healthCheckPath: /` in `render.yaml`
  expects `/` to return 200. If you customized `index.php` to require
  POST, change the healthcheck path.

### Conversion times out or fails with "Chrome failed to produce a PDF"

Usually OOM on a large document. Either:

- Reduce the document size (split into chapters, fewer fonts)
- Switch `FONT_SOURCE=system` in the **Environment** tab
- Upgrade to Starter

Logs will show `Allocation failed - JavaScript heap out of memory` or
similar.

### "Could not find render.yaml"

Make sure `render.yaml` is at the **root** of the repo on the branch
you connected, not in a subdirectory. Confirm by visiting
`https://github.com/<you>/tool-markdown-to-pdf/blob/main/render.yaml`.

### `GOOGLE_FONTS_API_KEY` keeps disappearing

The `sync: false` flag in `render.yaml` is the right behavior — the
value lives only in Render's UI, never in git. To set it: **Environment**
tab → **Edit** → paste the key → **Save Changes**. It persists across
deploys.

### I want to start over from scratch

Service page → **Settings** (left sidebar) → scroll to bottom →
**Delete Service**. Then redeploy from the Blueprint.

---

## Alternatives to Render

If the 15-minute auto-sleep is a dealbreaker for your use case, here
are other free / cheap PaaS options that all work from the same
`Dockerfile`:

| Provider | Auto-sleep? | Free tier | Cold start |
|---|---|---|---|
| **Google Cloud Run** | Scales to zero with sub-second wake | 2M req/mo + 360k GB-s RAM | ~1 s |
| **Fly.io** | No (always-on) | $5/mo signup credit, then pay | n/a |
| **Railway** | No | $5/mo trial credit, then pay | n/a |
| **Oracle Cloud "Always Free"** | No (real VM) | 4 ARM cores + 24 GB RAM | n/a |

Each requires more setup than Render (CI config, secret management,
sometimes a credit card). Render was chosen as the default because of
its closest-to-Read-The-Docs UX.

---

That's it. After you push, Render handles everything else.
