import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import type { SiteAuthSession } from '../types/auth';

const AUTH_PREFIX = '@struxa/auth:';
const useSecureStore = Platform.OS !== 'web';

async function readRaw(siteId: string): Promise<string | null> {
  const key = `${AUTH_PREFIX}${siteId}`;
  if (useSecureStore) {
    const secure = await SecureStore.getItemAsync(key);
    if (secure) {
      return secure;
    }
    const legacy = await AsyncStorage.getItem(key);
    if (legacy) {
      await SecureStore.setItemAsync(key, legacy);
      await AsyncStorage.removeItem(key);
      return legacy;
    }
    return null;
  }
  return AsyncStorage.getItem(key);
}

async function writeRaw(siteId: string, value: string): Promise<void> {
  const key = `${AUTH_PREFIX}${siteId}`;
  if (useSecureStore) {
    await SecureStore.setItemAsync(key, value);
    await AsyncStorage.removeItem(key);
    return;
  }
  await AsyncStorage.setItem(key, value);
}

async function removeRaw(siteId: string): Promise<void> {
  const key = `${AUTH_PREFIX}${siteId}`;
  if (useSecureStore) {
    await SecureStore.deleteItemAsync(key);
  }
  await AsyncStorage.removeItem(key);
}

export async function loadSiteAuth(siteId: string): Promise<SiteAuthSession | null> {
  const raw = await readRaw(siteId);
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
  await writeRaw(siteId, JSON.stringify(session));
}

export async function clearSiteAuth(siteId: string): Promise<void> {
  await removeRaw(siteId);
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
