# Struxa mobile app (Phase 2)

Expo/React Native client for Struxa CMS sites. Users add one or more site URLs; each site loads branding, tabs, and navigation from the CMS bootstrap API.

## Requirements

- Node.js 20+
- Expo Go app (device/simulator) or iOS/Android dev build

## Quick start

```bash
cd mobile-app
npm install
npm start
```

Scan the QR code with Expo Go, or press `i` / `a` for iOS/Android simulator.

## First run

1. Open the app → **Add a Struxa site**
2. Enter your site URL, e.g. `http://localhost:3439` (simulator) or `https://yourdomain.com`
3. The app fetches `GET /api/v1/mobile/bootstrap` and shows a branded tab shell

## Features (Phase 2–3)

- **Site registry** — add, remove, switch sites (AsyncStorage)
- **Bootstrap fetch + cache** — 5-minute TTL per site, pull-to-refresh via header ↻
- **Theming** — accent color, logo, site name from bootstrap
- **Dynamic tab bar** — from `mobile.tabs` in bootstrap
- **Content browsing** — type list → entry list (pagination) → entry detail with images
- **Account tab** — per-site login, register, profile, sign out (JWT)
- **Placeholder screens** — search, shop (later phases)

## Project layout

```
app/                 Expo Router screens
src/context/         SitesProvider (registry + bootstrap cache)
src/lib/             URL normalize, bootstrap fetch, storage
src/components/      Site shell, tab bar, placeholder screens
src/types/           Bootstrap TypeScript types
```

## Connecting to local Docker

| Environment | URL |
|-------------|-----|
| iOS Simulator | `http://localhost:3439` |
| Android Emulator | `http://10.0.2.2:3439` |
| Physical device | `http://<your-lan-ip>:3439` |

Ensure **Admin → Site → Mobile app** has bootstrap enabled and run `composer migrate` for migration `055`.

## CMS docs

- [docs/mobile.md](../docs/mobile.md) — bootstrap API
- [docs/mobile-phases.md](../docs/mobile-phases.md) — full roadmap
