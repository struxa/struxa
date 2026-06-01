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

**Struxa Catalog Admin** (`struxa-admin` plugin) can **regenerate** `repo.json` from `public/struxa-dist/publish.json` plus whatever ZIPs exist in `public/struxa-dist/zips/`. If `publish.json` there only lists `struxa-admin`, other plugins disappear from the catalog. Keep `public/struxa-dist/publish.json` in sync with `struxa-dist/publish.json` in git.

## 1. Build from the CMS repo

From the **Struxa CMS** repo root, rebuild ZIPs and `repo.json` after changing themes/plugins:

```bash
./scripts/build-struxa-dist.sh
```

## 2. Deploy to struxapoint.com (required)

Upload **`struxa-dist/`** (including `repo.json`, `zips/`, and `.htaccess`) to your hosting docroot:

| Public URL | File on disk |
|------------|----------------|
| `https://struxapoint.com/struxa-dist/repo.json` | `struxa-dist/repo.json` |
| `https://struxapoint.com/struxa-dist/zips/{slug}.zip` | `struxa-dist/zips/{slug}.zip` |

```bash
rsync -avz public/struxa-dist/ USER@HOST:~/public_html/struxapoint/public/struxa-dist/
curl -sS https://struxapoint.com/struxa-dist/repo.json | head
```

On the server (after git pull):

```bash
cd ~/public_html/struxapoint
bash scripts/deploy-struxa-dist-on-server.sh
```

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
