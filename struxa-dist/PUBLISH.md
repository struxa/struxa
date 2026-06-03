# Publish themes and plugins on struxapoint.com

Themes and plugin ZIPs are **not** stored in the CMS git repo on customer servers. They live in a **static folder on struxapoint.com**:

```
struxa-dist/                 ← upload to site docroot (e.g. public_html/struxa-dist/)
  repo.json                  ← catalog index (themes + plugins)
  zips/
    default.zip
    mailing-list-plugin.zip
    …
```

CMS sites download from `https://struxapoint.com/struxa-dist/repo.json` and install ZIPs from `https://struxapoint.com/struxa-dist/zips/{slug}.zip`.

**On struxapoint.com** the live folder is `~/public_html/struxapoint/public/struxa-dist/` (not the repo root `struxa-dist/` alone).

**Struxa Catalog Admin** (`struxa-admin` plugin) **owns the live catalog** on production: it writes `repo.json`, `repo-summary.json`, and serves ZIPs from `public/struxa-dist/zips/` after you approve submissions. **CMS self-updates and FTP update ZIPs do not overwrite** those files (see `CmsSelfUpdater`, `.gitattributes`, and `scripts/build-safe-update-zip.sh`).

The git repo still contains `public/struxa-dist/` for **local dev and build reference** only (`./scripts/build-struxa-dist.sh`).

## 1. Build from the CMS repo (dev / reference)

From the **Struxa CMS** repo root, rebuild ZIPs and `repo.json` after changing themes/plugins:

```bash
./scripts/build-struxa-dist.sh
```

This syncs into `public/struxa-dist/` on your machine. **Do not rsync that folder to production** — it would overwrite the live catalog.

## 2. Production catalog on struxapoint.com

| Public URL | Source on production |
|------------|----------------------|
| `https://struxapoint.com/struxa-dist/repo.json` | **struxa-admin → Regenerate catalog** |
| `https://struxapoint.com/struxa-dist/zips/{slug}.zip` | Approved submissions + uploads in Admin |

After deploying **CMS code** (not catalog files):

1. Update the **struxa-admin** plugin from Admin → Plugins if needed.
2. Approve or upload packages in **Admin → Struxa catalog**.
3. Click **Regenerate catalog** so `repo.json` and ZIP URLs match the live registry.

Optional sanity check on the server (does **not** copy from git):

```bash
cd ~/public_html/struxapoint
bash scripts/deploy-struxa-dist-on-server.sh
curl -sS https://struxapoint.com/struxa-dist/repo.json | head
```

### Theme ZIP still shows an old version (e.g. 1.0.38)

`repo.json` reads **`theme.json` inside `public/struxa-dist/zips/struxa-theme.zip`**, which is built from **`themes/struxa-theme/` on the server**. If that folder was never updated, regenerate and `build-struxa-dist.sh` both keep publishing the old version.

```bash
cd ~/public_html/struxapoint
grep '"version"' themes/struxa-theme/theme.json    # must be 1.0.41+ after deploy
git pull origin main                               # if you deploy via git
# or: Admin → Updates → install latest CMS

bash scripts/republish-bundled-theme.sh            # rebuild ZIP from themes/
# Admin → Struxa catalog → Regenerate catalog    # refresh repo.json
```

Use `bash scripts/...` if `./scripts/...` returns Permission denied.

If `themes/struxa-theme/theme.json` stays at **1.0.38** after `git pull`, the server tree was not updated (FTP-only deploy, old branch, or local overrides). Refresh only the theme from GitHub:

```bash
bash scripts/sync-struxa-theme-from-github.sh
bash scripts/republish-bundled-theme.sh
# Admin → Struxa catalog → Regenerate catalog
```

**Deploy CMS only** — merge application code via git pull, self-update, or FTP safe ZIP. Never upload git’s `public/struxa-dist/repo.json` or `zips/` over the live folder.

## 3. Optional: GitHub mirror (`struxa/struxa-dist`)

### Option A — standalone repo

```bash
cd struxa-dist
git init -b main
git add repo.json zips/ README.md PUBLISH.md .gitignore
git commit -m "Publish Struxa distribution catalog"
git remote add origin git@github.com:struxa/struxa-dist.git
git push -u origin main
```

Create the empty repo on GitHub first: **New repository → `struxa-dist` → no README**.

### Option B — copy to a separate checkout

```bash
rsync -av --delete struxa-dist/ ../struxa-dist-publish/
cd ../struxa-dist-publish && git init && …
```

## 4. CMS sites (.env)

On each Struxa install:

```env
STRUXA_DIST_CATALOG_URL=https://struxapoint.com/struxa-dist/repo.json
```

Then use **Themes → Browse catalog** and **Extensions → Plugins → Browse catalog**.
