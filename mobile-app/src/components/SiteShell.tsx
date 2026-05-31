import { useEffect, useMemo, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { useRouter } from 'expo-router';

import { BottomTabBar } from '../components/BottomTabBar';
import { ErrorView, LoadingView } from '../components/StatusViews';
import { SiteHeader } from '../components/SiteHeader';
import { TabScreen } from '../components/TabScreen';
import { useSites } from '../context/SitesContext';
import { buildSiteTheme } from '../theme/siteTheme';
import type { BootstrapData } from '../types/bootstrap';

type Props = {
  siteId: string;
};

export function SiteShell({ siteId }: Props) {
  const router = useRouter();
  const { sites, refreshSite, getBootstrap, loadingSiteId } = useSites();
  const [bootstrap, setBootstrap] = useState<BootstrapData | null>(() => getBootstrap(siteId));
  const [loadError, setLoadError] = useState<string | null>(null);
  const [activeTabId, setActiveTabId] = useState<string>('');

  const site = sites.find((entry) => entry.id === siteId) ?? null;
  const theme = useMemo(
    () => buildSiteTheme(bootstrap?.branding.accent_color),
    [bootstrap?.branding.accent_color],
  );

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const data = await refreshSite(siteId);
        if (!cancelled) {
          setBootstrap(data);
          setLoadError(null);
          setActiveTabId((current) => current || data.mobile.tabs[0]?.id || 'home');
        }
      } catch (err) {
        if (!cancelled) {
          setLoadError(err instanceof Error ? err.message : 'Could not load site.');
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [refreshSite, siteId]);

  useEffect(() => {
    if (bootstrap && bootstrap.mobile.tabs.length > 0) {
      setActiveTabId((current) =>
        bootstrap.mobile.tabs.some((tab) => tab.id === current)
          ? current
          : bootstrap.mobile.tabs[0].id,
      );
    }
  }, [bootstrap]);

  if (!site) {
    return (
      <ErrorView
        message="That site is not in your list anymore."
        onRetry={() => router.replace('/sites')}
        theme={buildSiteTheme()}
      />
    );
  }

  if (!bootstrap && (loadingSiteId === siteId || !loadError)) {
    return <LoadingView label={`Connecting to ${site.label}…`} theme={theme} />;
  }

  if (!bootstrap && loadError) {
    return (
      <ErrorView
        message={loadError}
        onRetry={() => {
          setLoadError(null);
          refreshSite(siteId, true)
            .then((data) => {
              setBootstrap(data);
            })
            .catch((err) => {
              setLoadError(err instanceof Error ? err.message : 'Could not load site.');
            });
        }}
        theme={theme}
      />
    );
  }

  if (!bootstrap) {
    return <LoadingView theme={theme} />;
  }

  const activeTab =
    bootstrap.mobile.tabs.find((tab) => tab.id === activeTabId) ?? bootstrap.mobile.tabs[0];

  return (
    <View style={[styles.root, { backgroundColor: theme.background }]}>
      <SiteHeader
        bootstrap={bootstrap}
        onManageSites={() => router.push('/sites')}
        onRefresh={() => {
          refreshSite(siteId, true)
            .then(setBootstrap)
            .catch((err) => setLoadError(err instanceof Error ? err.message : 'Could not refresh.'));
        }}
        refreshing={loadingSiteId === siteId}
        theme={theme}
      />
      <View style={styles.body}>
        {activeTab ? <TabScreen bootstrap={bootstrap} tab={activeTab} theme={theme} /> : null}
      </View>
      <BottomTabBar
        activeTabId={activeTab?.id ?? ''}
        onSelect={setActiveTabId}
        tabs={bootstrap.mobile.tabs}
        theme={theme}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
  },
  body: {
    flex: 1,
  },
});
