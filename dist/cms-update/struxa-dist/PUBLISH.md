# Publish this catalog to GitHub and struxapoint.com

## 1. GitHub repository

From the **Struxa CMS** repo root, rebuild ZIPs and `repo.json` after changing themes/plugins:

```bash
./scripts/build-struxa-dist.sh
```

### Option A — standalone `struxa-dist` repo (recommended)

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

## 2. Deploy to struxapoint.com

Upload the **contents** of this folder so these URLs respond over HTTPS:

| URL | File |
|-----|------|
| `https://struxapoint.com/struxa-dist/repo.json` | `repo.json` |
| `https://struxapoint.com/struxa-dist/zips/{slug}.zip` | `zips/{slug}.zip` |

Examples: SFTP/rsync to the site docroot, S3 + CloudFront, or GitHub Pages with a `struxa-dist/` path.

After deploy, verify:

```bash
curl -sS https://struxapoint.com/struxa-dist/repo.json | head
```

## 3. CMS sites (.env)

On each Struxa install:

```env
STRUXA_DIST_CATALOG_URL=https://struxapoint.com/struxa-dist/repo.json
```

Then use **Themes → Browse catalog** and **Extensions → Plugins → Browse catalog**.
