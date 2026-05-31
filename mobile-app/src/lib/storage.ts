import AsyncStorage from '@react-native-async-storage/async-storage';

import type { CachedBootstrap, RegisteredSite } from '../types/bootstrap';

const SITES_KEY = '@struxa/sites';
const ACTIVE_SITE_KEY = '@struxa/active_site';
const BOOTSTRAP_PREFIX = '@struxa/bootstrap:';

export async function loadSites(): Promise<RegisteredSite[]> {
  const raw = await AsyncStorage.getItem(SITES_KEY);
  if (!raw) {
    return [];
  }
  try {
    const parsed = JSON.parse(raw) as RegisteredSite[];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

export async function saveSites(sites: RegisteredSite[]): Promise<void> {
  await AsyncStorage.setItem(SITES_KEY, JSON.stringify(sites));
}

export async function loadActiveSiteId(): Promise<string | null> {
  return AsyncStorage.getItem(ACTIVE_SITE_KEY);
}

export async function saveActiveSiteId(siteId: string | null): Promise<void> {
  if (siteId === null) {
    await AsyncStorage.removeItem(ACTIVE_SITE_KEY);
    return;
  }
  await AsyncStorage.setItem(ACTIVE_SITE_KEY, siteId);
}

export async function loadBootstrapCache(siteId: string): Promise<CachedBootstrap | null> {
  const raw = await AsyncStorage.getItem(`${BOOTSTRAP_PREFIX}${siteId}`);
  if (!raw) {
    return null;
  }
  try {
    const parsed = JSON.parse(raw) as CachedBootstrap;
    if (!parsed?.data || typeof parsed.fetchedAt !== 'number') {
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

export async function saveBootstrapCache(siteId: string, cache: CachedBootstrap): Promise<void> {
  await AsyncStorage.setItem(`${BOOTSTRAP_PREFIX}${siteId}`, JSON.stringify(cache));
}

export async function clearBootstrapCache(siteId: string): Promise<void> {
  await AsyncStorage.removeItem(`${BOOTSTRAP_PREFIX}${siteId}`);
}
