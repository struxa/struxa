# Releasing Struxa to the App Store and Play Store

This app is an **Expo SDK 54** project. Use [EAS Build](https://docs.expo.dev/build/introduction/) for store binaries; keep using **Expo Go** for day-to-day development.

## Prerequisites

- Apple Developer account (iOS) and/or Google Play Console (Android)
- Expo account: `npm install -g eas-cli && eas login`
- App identifiers in `app.json`:
  - iOS: `com.struxa.app`
  - Android: `com.struxa.app`

## Configure EAS (one-time)

```bash
cd mobile-app
eas build:configure
```

Review `eas.json` profiles (`development`, `preview`, `production`).

## Build

```bash
# iOS App Store
eas build --platform ios --profile production

# Android Play Store (AAB)
eas build --platform android --profile production
```

## Submit

```bash
eas submit --platform ios
eas submit --platform android
```

## Store listing checklist

- [ ] App icon (`assets/icon.png`) and splash assets
- [ ] Privacy policy URL (required for apps that load arbitrary customer sites)
- [ ] Screenshots (6.7", 5.5" iPhone; phone + tablet Android)
- [ ] Short description: multi-site client for Struxa CMS
- [ ] Deep link scheme `struxa://` documented for site onboarding QR codes

## Deep links

Sites expose `mobile.add_site_deeplink` in bootstrap (e.g. `struxa://add-site?url=https://yoursite.com`). Admin **Mobile app → Add to Struxa app** generates a matching QR code.

## Push notifications

Not included in the current release. Per-site push can be added when operators need order/status alerts.

## Versioning

- App semver: `mobile-app/package.json` and `app.json` → `version`
- CMS Struxa release number is separate (`src/CmsVersion.php`); do not conflate the two.
