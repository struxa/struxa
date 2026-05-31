import AsyncStorage from '@react-native-async-storage/async-storage';

import type { SiteAuthSession } from '../types/auth';

const AUTH_PREFIX = '@struxa/auth:';

export async function loadSiteAuth(siteId: string): Promise<SiteAuthSession | null> {
  const raw = await AsyncStorage.getItem(`${AUTH_PREFIX}${siteId}`);
  if (!raw) {
    return null;
  }
  try {
    const parsed = JSON.parse(raw) as SiteAuthSession;
    if (!parsed?.accessToken || !parsed.refreshToken || !parsed.user) {
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

export async function saveSiteAuth(siteId: string, session: SiteAuthSession): Promise<void> {
  await AsyncStorage.setItem(`${AUTH_PREFIX}${siteId}`, JSON.stringify(session));
}

export async function clearSiteAuth(siteId: string): Promise<void> {
  await AsyncStorage.removeItem(`${AUTH_PREFIX}${siteId}`);
}

export function sessionFromAuthResponse(data: {
  access_token: string;
  refresh_token: string;
  expires_at: number;
  user: SiteAuthSession['user'];
}): SiteAuthSession {
  return {
    accessToken: data.access_token,
    refreshToken: data.refresh_token,
    expiresAt: data.expires_at * 1000,
    user: data.user,
  };
}

export function isAccessTokenExpired(session: SiteAuthSession, skewMs = 30_000): boolean {
  return Date.now() + skewMs >= session.expiresAt;
}
