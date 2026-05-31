import { Image } from 'expo-image';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { BootstrapData } from '../types/bootstrap';
import type { SiteTheme } from '../theme/siteTheme';

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
          paddingTop: insets.top + 8,
          backgroundColor: theme.surface,
          borderBottomColor: theme.border,
        },
      ]}
    >
      <View style={styles.row}>
        {bootstrap.branding.logo_url ? (
          <Image
            accessibilityLabel={`${bootstrap.site.name} logo`}
            contentFit="contain"
            source={{ uri: bootstrap.branding.logo_url }}
            style={styles.logo}
          />
        ) : (
          <View style={[styles.logoFallback, { backgroundColor: theme.accentSoft }]}>
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
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Refresh site"
          onPress={onRefresh}
          style={({ pressed }) => [styles.iconBtn, { opacity: pressed ? 0.7 : 1 }]}
        >
          <Text style={[styles.iconBtnText, { color: theme.accent }]}>{refreshing ? '…' : '↻'}</Text>
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Manage sites"
          onPress={onManageSites}
          style={({ pressed }) => [styles.iconBtn, { opacity: pressed ? 0.7 : 1 }]}
        >
          <Text style={[styles.iconBtnText, { color: theme.textMuted }]}>☰</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    paddingHorizontal: 16,
    paddingBottom: 12,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  logo: {
    width: 40,
    height: 40,
    borderRadius: 10,
  },
  logoFallback: {
    width: 40,
    height: 40,
    borderRadius: 10,
    alignItems: 'center',
    justifyContent: 'center',
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
    fontSize: 17,
    fontWeight: '700',
  },
  tagline: {
    fontSize: 13,
    marginTop: 2,
  },
  iconBtn: {
    width: 36,
    height: 36,
    alignItems: 'center',
    justifyContent: 'center',
  },
  iconBtnText: {
    fontSize: 20,
    fontWeight: '700',
  },
});
