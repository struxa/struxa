import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { MobileTab } from '../types/bootstrap';
import type { SiteTheme } from '../theme/siteTheme';

type Props = {
  tabs: MobileTab[];
  activeTabId: string;
  theme: SiteTheme;
  onSelect: (tabId: string) => void;
};

export function BottomTabBar({ tabs, activeTabId, theme, onSelect }: Props) {
  const insets = useSafeAreaInsets();

  if (tabs.length === 0) {
    return null;
  }

  return (
    <View
      style={[
        styles.wrap,
        {
          paddingBottom: Math.max(insets.bottom, 10),
          backgroundColor: theme.surface,
          borderTopColor: theme.border,
        },
      ]}
    >
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.row}>
        {tabs.map((tab) => {
          const active = tab.id === activeTabId;
          return (
            <Pressable
              key={tab.id}
              accessibilityRole="button"
              accessibilityState={{ selected: active }}
              onPress={() => onSelect(tab.id)}
              style={({ pressed }) => [
                styles.tab,
                {
                  backgroundColor: active ? theme.accentSoft : 'transparent',
                  opacity: pressed ? 0.85 : 1,
                },
              ]}
            >
              <Text style={[styles.tabIcon, { color: active ? theme.accent : theme.textMuted }]}>
                {iconForType(tab.type)}
              </Text>
              <Text
                numberOfLines={1}
                style={[styles.tabLabel, { color: active ? theme.accent : theme.textMuted }]}
              >
                {tab.label}
              </Text>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

function iconForType(type: string): string {
  switch (type) {
    case 'home':
      return '⌂';
    case 'content':
      return '▤';
    case 'search':
      return '⌕';
    case 'shop':
      return '◆';
    default:
      return '•';
  }
}

const styles = StyleSheet.create({
  wrap: {
    borderTopWidth: StyleSheet.hairlineWidth,
    paddingTop: 8,
    paddingHorizontal: 8,
  },
  row: {
    flexDirection: 'row',
    gap: 8,
    paddingHorizontal: 4,
  },
  tab: {
    minWidth: 72,
    alignItems: 'center',
    borderRadius: 14,
    paddingHorizontal: 12,
    paddingVertical: 8,
  },
  tabIcon: {
    fontSize: 16,
    marginBottom: 2,
  },
  tabLabel: {
    fontSize: 12,
    fontWeight: '600',
  },
});
