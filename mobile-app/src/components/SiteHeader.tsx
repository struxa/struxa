import { Image } from 'expo-image';
import { StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { radius, spacing } from '../theme/layout';
import type { BootstrapData } from '../types/bootstrap';
import type { SiteTheme } from '../theme/siteTheme';
import { IconButton } from './ui/primitives';

type Props = {
  bootstrap: BootstrapData;
  theme: SiteTheme;
  onManageSites: () => void;
  onRefresh: () => void;
  refreshing?: boolean;
};

export function SiteHeader({ bootstrap, theme, onManageSites, onRefresh, refreshing }: Props) {
  const insets = useSafeAreaInsets();

  return (
    <View
      style={[
        styles.wrap,
        {
          paddingTop: insets.top + spacing.sm,
          backgroundColor: theme.surface,
          borderBottomColor: theme.border,
        },
      ]}
    >
      <View style={[styles.accentStrip, { backgroundColor: theme.accent }]} />
      <View style={styles.row}>
        {bootstrap.branding.logo_url ? (
          <Image
            accessibilityLabel={`${bootstrap.site.name} logo`}
            contentFit="contain"
            source={{ uri: bootstrap.branding.logo_url }}
            style={[styles.logo, { borderColor: theme.border }]}
          />
        ) : (
          <View style={[styles.logoFallback, { backgroundColor: theme.accentSoft, borderColor: theme.border }]}>
            <Text style={[styles.logoFallbackText, { color: theme.accent }]}>
              {bootstrap.site.name.slice(0, 1).toUpperCase()}
            </Text>
          </View>
        )}
        <View style={styles.meta}>
          <Text numberOfLines={1} style={[styles.title, { color: theme.text }]}>
            {bootstrap.site.name}
          </Text>
          {bootstrap.site.tagline ? (
            <Text numberOfLines={1} style={[styles.tagline, { color: theme.textMuted }]}>
              {bootstrap.site.tagline}
            </Text>
          ) : null}
        </View>
        <IconButton
          accent
          disabled={refreshing}
          icon="refresh"
          label="Refresh site"
          onPress={onRefresh}
          theme={theme}
        />
        <IconButton icon="menu" label="Manage sites" onPress={onManageSites} theme={theme} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    paddingHorizontal: spacing.md,
    paddingBottom: spacing.md,
  },
  accentStrip: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 2,
    opacity: 0.9,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  logo: {
    width: 44,
    height: 44,
    borderRadius: radius.md,
    borderWidth: StyleSheet.hairlineWidth,
  },
  logoFallback: {
    width: 44,
    height: 44,
    borderRadius: radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: StyleSheet.hairlineWidth,
  },
  logoFallbackText: {
    fontSize: 18,
    fontWeight: '800',
  },
  meta: {
    flex: 1,
    minWidth: 0,
  },
  title: {
    fontSize: 18,
    fontWeight: '800',
    letterSpacing: -0.3,
  },
  tagline: {
    fontSize: 13,
    marginTop: 2,
  },
});
