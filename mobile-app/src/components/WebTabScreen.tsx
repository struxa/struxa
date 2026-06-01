import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { WebView } from 'react-native-webview';

import type { BootstrapData, MobileTab } from '../types/bootstrap';
import type { SiteTheme } from '../theme/siteTheme';
import { ErrorView } from './StatusViews';

type Props = {
  bootstrap: BootstrapData;
  tab: MobileTab;
  theme: SiteTheme;
};

export function WebTabScreen({ bootstrap, tab, theme }: Props) {
  const url = tab.url?.trim() ?? '';

  if (url === '') {
    return (
      <View style={[styles.center, { backgroundColor: theme.background }]}>
        <ErrorView
          message={
            tab.type === 'plugin'
              ? `Plugin tab “${tab.plugin_slug ?? tab.id}” has no URL. Add a url field in mobile.bootstrap or tab JSON.`
              : 'This tab has no URL configured.'
          }
          theme={theme}
        />
      </View>
    );
  }

  if (!/^https:\/\//i.test(url)) {
    return (
      <View style={[styles.center, { backgroundColor: theme.background }]}>
        <ErrorView message="This tab URL must use HTTPS." theme={theme} />
      </View>
    );
  }

  return (
    <WebView
      originWhitelist={['https://*']}
      pullToRefreshEnabled
      renderLoading={() => (
        <View style={styles.loader}>
          <ActivityIndicator color={theme.accent} size="large" />
        </View>
      )}
      source={{ uri: url }}
      startInLoadingState
      style={{ flex: 1, backgroundColor: theme.background }}
    />
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
  },
  loader: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
