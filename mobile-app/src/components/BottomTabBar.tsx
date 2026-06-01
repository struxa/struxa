import { Ionicons } from '@expo/vector-icons';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { radius, spacing } from '../theme/layout';
import type { MobileTab } from '../types/bootstrap';
import type { SiteTheme } from '../theme/siteTheme';
import { tabIconName } from './ui/tabIcons';

type Props = {
  tabs: MobileTab[];
  activeTabId: string;
  theme: SiteTheme;
  onSelect: (tabId: string) => void;
};

export function BottomTabBar({ tabs, activeTabId, theme, onSelect }: Props) {
  const insets = useSafeAreaInsets();
  const evenLayout = tabs.length <= 6;

  if (tabs.length === 0) {
    return null;
  }

  return (
    <View
      style={[
        styles.wrap,
        {
          paddingBottom: Math.max(insets.bottom, spacing.sm),
          backgroundColor: theme.surface,
          borderTopColor: theme.border,
        },
      ]}
    >
      <View style={[styles.row, evenLayout && styles.rowEven]}>
        {tabs.map((tab) => {
          const active = tab.id === activeTabId;
          const icon = tabIconName(tab.type, active);
          return (
            <Pressable
              key={tab.id}
              accessibilityRole="button"
              accessibilityState={{ selected: active }}
              onPress={() => onSelect(tab.id)}
              style={({ pressed }) => [
                styles.tab,
                evenLayout && styles.tabEven,
                {
                  backgroundColor: active ? theme.accentSoft : 'transparent',
                  opacity: pressed ? 0.85 : 1,
                },
              ]}
            >
              {active ? <View style={[styles.activeBar, { backgroundColor: theme.accent }]} /> : null}
              <Ionicons color={active ? theme.accent : theme.textMuted} name={icon} size={22} />
              <Text
                numberOfLines={1}
                style={[styles.tabLabel, { color: active ? theme.accent : theme.textMuted }]}
              >
                {tab.label}
              </Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    borderTopWidth: StyleSheet.hairlineWidth,
    paddingTop: spacing.sm,
    paddingHorizontal: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    gap: spacing.xs,
  },
  rowEven: {
    justifyContent: 'space-between',
  },
  tab: {
    minWidth: 68,
    alignItems: 'center',
    borderRadius: radius.md,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.sm,
    gap: 3,
    position: 'relative',
    overflow: 'hidden',
  },
  tabEven: {
    flex: 1,
    minWidth: 0,
  },
  activeBar: {
    position: 'absolute',
    top: 0,
    left: spacing.sm,
    right: spacing.sm,
    height: 2,
    borderRadius: radius.pill,
  },
  tabLabel: {
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.2,
  },
});
