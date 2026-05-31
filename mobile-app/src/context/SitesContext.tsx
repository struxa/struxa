import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';

import {
  BootstrapError,
  fetchBootstrap,
  isBootstrapStale,
} from '../lib/bootstrap';
import {
  clearBootstrapCache,
  loadActiveSiteId,
  loadBootstrapCache,
  loadSites,
  saveActiveSiteId,
  saveBootstrapCache,
  saveSites,
} from '../lib/storage';
import { formatSiteLabel, normalizeSiteUrl, siteIdFromUrl } from '../lib/url';
import type { BootstrapData, CachedBootstrap, RegisteredSite } from '../types/bootstrap';

type SitesContextValue = {
  ready: boolean;
  sites: RegisteredSite[];
  activeSiteId: string | null;
  bootstraps: Record<string, CachedBootstrap>;
  loadingSiteId: string | null;
  error: string | null;
  addSite: (urlInput: string) => Promise<void>;
  removeSite: (siteId: string) => Promise<void>;
  setActiveSite: (siteId: string) => Promise<void>;
  refreshSite: (siteId: string, force?: boolean) => Promise<BootstrapData>;
  getBootstrap: (siteId: string) => BootstrapData | null;
  clearError: () => void;
};

const SitesContext = createContext<SitesContextValue | null>(null);

export function SitesProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [sites, setSites] = useState<RegisteredSite[]>([]);
  const [activeSiteId, setActiveSiteIdState] = useState<string | null>(null);
  const [bootstraps, setBootstraps] = useState<Record<string, CachedBootstrap>>({});
  const [loadingSiteId, setLoadingSiteId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      const [storedSites, storedActive] = await Promise.all([loadSites(), loadActiveSiteId()]);
      const cacheEntries = await Promise.all(
        storedSites.map(async (site) => {
          const cache = await loadBootstrapCache(site.id);
          return cache ? ([site.id, cache] as const) : null;
        }),
      );

      if (cancelled) {
        return;
      }

      const nextCache: Record<string, CachedBootstrap> = {};
      for (const entry of cacheEntries) {
        if (entry) {
          nextCache[entry[0]] = entry[1];
        }
      }

      setSites(storedSites);
      setActiveSiteIdState(storedActive);
      setBootstraps(nextCache);
      setReady(true);
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const refreshSite = useCallback(async (siteId: string, force = false): Promise<BootstrapData> => {
    const site = sites.find((entry) => entry.id === siteId);
    if (!site) {
      throw new BootstrapError('Site not found in your list.', 'not_found');
    }

    const cached = bootstraps[siteId];
    if (!force && cached && !isBootstrapStale(cached.fetchedAt)) {
      return cached.data;
    }

    setLoadingSiteId(siteId);
    setError(null);

    try {
      const data = await fetchBootstrap(site.url);
      const nextCache: CachedBootstrap = { data, fetchedAt: Date.now() };
      await saveBootstrapCache(siteId, nextCache);
      setBootstraps((prev) => ({ ...prev, [siteId]: nextCache }));

      if (site.label !== data.site.name) {
        const nextSites = sites.map((entry) =>
          entry.id === siteId ? { ...entry, label: data.site.name || entry.label } : entry,
        );
        setSites(nextSites);
        await saveSites(nextSites);
      }

      return data;
    } catch (err) {
      const message = err instanceof BootstrapError ? err.message : 'Could not load site bootstrap.';
      setError(message);
      throw err;
    } finally {
      setLoadingSiteId(null);
    }
  }, [bootstraps, sites]);

  const addSite = useCallback(async (urlInput: string) => {
    setError(null);
    const url = normalizeSiteUrl(urlInput);
    const id = siteIdFromUrl(url);

    if (sites.some((site) => site.id === id || site.url === url)) {
      throw new BootstrapError('That site is already in your list.', 'duplicate');
    }

    setLoadingSiteId(id);
    try {
      const data = await fetchBootstrap(url);
      const nextSite: RegisteredSite = {
        id,
        url,
        label: data.site.name || formatSiteLabel(url),
        addedAt: new Date().toISOString(),
      };
      const nextSites = [...sites, nextSite];
      const nextCache: CachedBootstrap = { data, fetchedAt: Date.now() };

      await Promise.all([
        saveSites(nextSites),
        saveBootstrapCache(id, nextCache),
        saveActiveSiteId(id),
      ]);

      setSites(nextSites);
      setBootstraps((prev) => ({ ...prev, [id]: nextCache }));
      setActiveSiteIdState(id);
    } catch (err) {
      const message = err instanceof BootstrapError ? err.message : 'Could not add that site.';
      setError(message);
      throw err;
    } finally {
      setLoadingSiteId(null);
    }
  }, [sites]);

  const removeSite = useCallback(async (siteId: string) => {
    const nextSites = sites.filter((site) => site.id !== siteId);
    const nextActive =
      activeSiteId === siteId ? (nextSites[0]?.id ?? null) : activeSiteId;

    await Promise.all([
      saveSites(nextSites),
      saveActiveSiteId(nextActive),
      clearBootstrapCache(siteId),
    ]);

    setSites(nextSites);
    setActiveSiteIdState(nextActive);
    setBootstraps((prev) => {
      const copy = { ...prev };
      delete copy[siteId];
      return copy;
    });
  }, [activeSiteId, sites]);

  const setActiveSite = useCallback(async (siteId: string) => {
    if (!sites.some((site) => site.id === siteId)) {
      return;
    }
    await saveActiveSiteId(siteId);
    setActiveSiteIdState(siteId);
  }, [sites]);

  const getBootstrap = useCallback(
    (siteId: string) => bootstraps[siteId]?.data ?? null,
    [bootstraps],
  );

  const value = useMemo(
    (): SitesContextValue => ({
      ready,
      sites,
      activeSiteId,
      bootstraps,
      loadingSiteId,
      error,
      addSite,
      removeSite,
      setActiveSite,
      refreshSite,
      getBootstrap,
      clearError: () => setError(null),
    }),
    [
      ready,
      sites,
      activeSiteId,
      bootstraps,
      loadingSiteId,
      error,
      addSite,
      removeSite,
      setActiveSite,
      refreshSite,
      getBootstrap,
    ],
  );

  return <SitesContext.Provider value={value}>{children}</SitesContext.Provider>;
}

export function useSites(): SitesContextValue {
  const ctx = useContext(SitesContext);
  if (!ctx) {
    throw new Error('useSites must be used within SitesProvider');
  }
  return ctx;
}
