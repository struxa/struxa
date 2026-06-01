import { useEffect, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';

import { useSites } from '../src/context/SitesContext';
import { buildSiteTheme } from '../src/theme/siteTheme';
import { ErrorView, LoadingView } from '../src/components/StatusViews';

function paramValue(value: string | string[] | undefined): string {
  if (Array.isArray(value)) {
    return value[0] ?? '';
  }
  return value ?? '';
}

export default function AddSiteDeepLinkScreen() {
  const router = useRouter();
  const params = useLocalSearchParams<{ url?: string | string[] }>();
  const { addSite } = useSites();
  const [error, setError] = useState<string | null>(null);
  const theme = buildSiteTheme();

  useEffect(() => {
    const url = paramValue(params.url);
    if (url.trim() === '') {
      setError('Missing site URL in link.');
      return;
    }

    addSite(url)
      .then(() => {
        router.replace('/');
      })
      .catch((err) => {
        setError(err instanceof Error ? err.message : 'Could not add site.');
      });
  }, [addSite, params.url, router]);

  if (error) {
    return (
      <View style={[styles.root, { backgroundColor: theme.background }]}>
        <ErrorView message={error} onRetry={() => router.replace('/sites/add')} theme={theme} />
      </View>
    );
  }

  return (
    <View style={[styles.root, { backgroundColor: theme.background }]}>
      <LoadingView label="Adding site…" theme={theme} />
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
  },
});
