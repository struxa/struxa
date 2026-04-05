# Plugin dependencies policy

This document is the **single policy** for where Composer packages live when plugins need third-party PHP libraries. It pairs with **`composer plugin-deps`** / **`bin/plugin-deps.php`** and **`composer check:plugin-deps`** / **`bin/plugin-dependency-health.php`**.

## Goals

- One obvious place to look for “what does this site need to run?”
- Predictable deploys: `composer install` at the repo root should satisfy **most** runtime code.
- Optional isolation for edge cases (forked packages, version conflicts, or appliances that ship a plugin `vendor/` tree).

## Terms

| Term | Meaning |
|------|---------|
| **Root metapackage** | The repository root **`composer.json`** / **`composer.lock`**. Everything in `App\` and shared runtime uses this autoloader first. |
| **Plugin-local Composer** | A **`plugins/{slug}/composer.json`** plus **`plugins/{slug}/vendor/`** (after `composer install` in that directory). |
| **Platform packages** | Composer requirements that are not installable packages: `php`, `ext-*`, `lib-*`. |

## Rules

### 1. Default: hoist shared libraries to the root

**When a plugin needs a normal Packagist (or VCS) library that the main app also benefits from (or that multiple plugins could use), add it to the root `composer.json` `require` section.**

Examples: HTTP clients, SDKs (e.g. Stripe), validators, UUID libraries.

**Why:** The CMS boots with **`vendor/autoload.php`** at the project root. Code in `plugins/{slug}/src/` is autoloaded via `plugin.json` PSR-4 and expects those classes to already exist via the root autoloader in the recommended setup.

### 2. When a plugin **MUST** use root `composer.json`

A plugin **must** declare its third-party dependency in the **root** `composer.json` when **any** of the following apply:

- The dependency is used from code that runs **without** loading `plugins/{slug}/vendor/autoload.php` (e.g. only root autoload is guaranteed in some entrypoints).
- You want **one** `composer install` at deploy to guarantee the class exists (simplest ops).
- Multiple plugins or core could use the same package (avoid duplicate trees and version skew).

**Operational check:** Run **`composer check:plugin-deps`**. It warns on packages listed only in a plugin’s `composer.json` and not in the root `require` (see health check below).

### 3. When a plugin-local `composer.json` is acceptable

A **`plugins/{slug}/composer.json`** is appropriate when:

- You need an **isolated** dependency tree (different major version than root, or a forked package name).
- You ship an **appliance** or tarball where the plugin directory is self-contained with its own `vendor/`.
- The dependency is **never** referenced from core or other plugins—only from that plugin’s code after its `vendor/autoload.php` is loaded.

**Even then**, document the plugin in README and run **`composer plugin-deps`** (or `composer plugin-deps:prod`) in CI after root `composer install`.

### 4. Plugin `composer.json` without `vendor/`

If **`plugins/{slug}/composer.json`** declares non-platform `require` entries, you **must** run **`composer install`** in that plugin directory (or **`composer plugin-deps`** from the repo root) so **`plugins/{slug}/vendor/autoload.php`** exists wherever that plugin is expected to run.

The health check treats missing `vendor/autoload.php` in that situation as an **error**.

### 5. Plugins without any `composer.json`

Valid for plugins that only use **PHP core**, **ext-***, and classes already provided by the root `vendor/` (or only their own `src/`). No extra steps.

### 6. Duplicate declaration (root + plugin)

It is **allowed** to list the same package in both root and plugin `composer.json` (e.g. Stripe in root for clarity, and again in the plugin for a standalone install). Prefer **matching major versions** to avoid confusion.

## Automation

| Command | Purpose |
|---------|---------|
| **`composer plugin-deps`** | Runs `composer install` in every `plugins/*/` that has a `composer.json`. |
| **`composer plugin-deps:prod`** | Same with `--no-dev`. |
| **`composer check:plugin-deps`** | Read-only health check: vendor present, root vs plugin packages, `main_class` resolvable. |
| **`php bin/plugin-dependency-health.php --active-only`** | Only plugins marked active in the database (needs DB + `.env`). |

**CI suggestion:**

```bash
composer install --no-interaction --prefer-dist
composer plugin-deps:prod
composer check:plugin-deps
```

Use **`composer check:plugin-deps --strict`** to fail the job on warnings (e.g. packages not hoisted to root).

## Health check exit codes

| Code | Meaning |
|------|---------|
| `0` | No errors (warnings may be printed). |
| `1` | At least one **error** (missing vendor, unresolved `main_class`, etc.). |
| `1` | With **`--strict`**, any **warning** also exits `1`. |

### Issue codes (check:plugin-deps)

| Code | Severity | Meaning |
|------|----------|---------|
| `active_only_no_database` | error | `--active-only` but DB unreachable. |
| `plugin_composer_lock_without_json` | error | `composer.lock` without `composer.json`. |
| `plugin_composer_invalid_json` | error | Plugin `composer.json` not valid JSON. |
| `plugin_vendor_autoload_missing` | error | Plugin declares third-party `require` but no `vendor/autoload.php`. |
| `main_class_unresolved` | error | `plugin.json` `main_class` not loadable after autoload bootstrap. |
| `package_not_in_root_composer` | warning | Third-party package only in plugin `composer.json`, not root `require`. |
| `orphan_vendor_directory` | warning | `vendor/` present without `composer.json`. |

## Summary for contributors

1. **Adding a new library for a plugin?** Start by adding it to **root** `composer.json` unless you have a concrete reason for isolation.
2. **Plugin has its own `composer.json`?** Run **`composer plugin-deps`** locally and in CI.
3. **Unsure?** Run **`composer check:plugin-deps`** and read the messages.

See also **`docs/plugins.md`** (general plugin layout and marketplace fields).
