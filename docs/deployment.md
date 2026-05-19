# Deployment

The repo includes a [`render.yaml`](../render.yaml) Blueprint so you can
host the converter on **[Render.com](https://render.com)** with auto-deploy
from GitHub on every push, free tier. Step-by-step:

---

## 1. Push the repo to GitHub

If you haven't already:

```bash
gh repo create md-to-pdf-converter --public --source . --push
```

Or with plain git:

```bash
git remote add origin git@github.com:<you>/md-to-pdf-converter.git
git push -u origin main
```

---

## 2. Create a Render account

[render.com](https://render.com) → sign up (GitHub OAuth is fastest — it
also lets Render see your repos).

No credit card required for the **Free** plan.

---

## 3. Deploy from the Blueprint

In the Render dashboard:

1. Click **New** → **Blueprint**.
2. Select your `md-to-pdf-converter` repo.
3. Render scans the repo, finds `render.yaml`, and shows what it will create
   — one Web Service named `md-to-pdf-converter`, Docker runtime, free plan.
4. You'll be prompted for any environment variables marked `sync: false`
   in the Blueprint — in our case that's **`GOOGLE_FONTS_API_KEY`**.
   Paste the key (optional — only needed if you'll later run
   `./bin/refresh_fonts` to refresh the bundled font catalog).
5. Click **Apply**. Render starts the first build.

The first build takes ~5–8 minutes (downloading the base image + apt
packages + fonts). Subsequent builds on push take 2–4 minutes thanks to
layer caching.

When it's live, Render gives you a URL like:

```
https://md-to-pdf-converter.onrender.com
```

That's it — open the URL and you can upload Markdown files.

---

## 4. Auto-deploy on push

Already set up via `autoDeploy: true` in `render.yaml`. From now on, every
`git push` to the default branch triggers Render to:

1. Pull the latest commit
2. Rebuild the Docker image
3. Replace the running container (zero-downtime swap on paid plans;
   brief blip on free plan)

Watch deploys at: Render dashboard → your service → **Deploys** tab.

---

## Free-tier characteristics

| | Free plan |
|---|---|
| RAM | 512 MB |
| CPU | 0.1 vCPU (shared) |
| Compute hours | 750 / month (≈1 service can run 24/7) |
| Bandwidth | 100 GB / month outbound |
| Builds | Unlimited |
| Custom domains | ✅ supported on free plan |
| HTTPS / SSL | ✅ free, auto-renewed |
| Auto-sleep | **Yes — after 15 min of inactivity** |
| Cold-start time | ~30–60 s on the next request after sleep |

### About the 15-minute sleep

Free Web Services on Render spin down when idle for 15 minutes. The next
request wakes them up — that first request takes 30–60 seconds. Subsequent
requests are fast again. For a personal/internal tool this is usually
fine; for production use upgrade to **Starter ($7/mo)** which removes
the sleep.

### About 512 MB RAM

Chromium plus the rest of the stack runs comfortably in ~400 MB for
typical documents, leaving headroom for one or two concurrent conversions.
**Very large documents (50+ pages of dense content) may OOM** on the free
plan; upgrade to Starter (~1 GB RAM) if you hit this.

---

## Setting / updating environment variables

In Render dashboard → your service → **Environment** tab:

- `FONT_SOURCE` — `google` (default) or `system`. Synced from
  `render.yaml`; edit either there + push, or in the UI for a one-off.
- `GOOGLE_FONTS_API_KEY` — set this in the UI. It's marked `sync: false`
  in the blueprint so the value never lives in git.

Changes take effect on the next deploy. Click **Manual Deploy** →
**Clear build cache & deploy** if a change isn't picked up.

---

## Custom domain *(optional, free)*

In your Render service → **Settings** → **Custom Domains** → add the
domain you want. Render gives you a CNAME target like
`md-to-pdf-converter.onrender.com`. Update your DNS, wait for propagation,
done. HTTPS is automatic.

---

## Monitoring & logs

Render dashboard → your service:

- **Logs** tab — live container stdout/stderr (Apache access + error log
  + PHP errors all go here).
- **Events** tab — deploys, restarts, scale operations.
- **Metrics** tab — CPU / RAM / response time (last 24 h on free plan).

For things only visible inside the container (e.g. running
`./bin/refresh_fonts`):

- Render → **Shell** tab → opens a web terminal into the running container.

---

## Troubleshooting

**Build fails partway through `apt-get install`.** Render's free build
infrastructure sometimes throttles big image builds. Click **Manual Deploy**
→ **Deploy latest commit** to retry — the layer cache helps.

**Service shows "deploy live" but `/` returns 502.** Check the **Logs**
tab. The most common cause: Apache failed to bind to `$PORT`. Verify the
entrypoint script ran — the first log line should be Apache's startup
banner mentioning the configured port.

**Conversion fails with Chrome / OOM errors.** You probably hit the
512 MB ceiling. Either reduce the document size, switch
`FONT_SOURCE=system` (skips Google Fonts download in render time, saves
~50 MB), or upgrade to **Starter**.

**Need to refresh the Google Fonts catalog.** Use Render's **Shell** tab
to open a terminal into the running container, then:

```bash
/var/www/html/bin/refresh_fonts
```

(Requires `GOOGLE_FONTS_API_KEY` set in the service's Environment.)

---

## Alternative: other free / cheap PaaS

If Render's 15-min sleep is a dealbreaker:

| Provider | Sleep? | Free tier | Auto-deploy |
|---|---|---|---|
| **Google Cloud Run** | No — scales to zero with sub-second cold start | Generous (2M requests/mo + 360k GB-s RAM) | via GitHub Actions or Cloud Build trigger |
| **Fly.io** | No (always-on machines) | $5/mo signup credit, then pay | via `flyctl deploy` |
| **Railway** | No | $5/mo trial credit, then pay | Native GitHub |
| **Oracle Cloud "Always Free"** | No (real VM) | 4 ARM cores + 24 GB RAM | Self-managed; add GitHub Actions SSH-deploy |

The repo's `Dockerfile` is the universal substrate — any of these can run
it. Render was chosen as the default because of its closest-to-Read The
Docs UX for repository-based deploy.
