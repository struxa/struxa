import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BodyText, Card, PageTitle, ScreenScroll } from '../../src/components/ui/primitives';
import { useSites } from '../../src/context/SitesContext';
import { radius, spacing } from '../../src/theme/layout';
import { buildSiteTheme } from '../../src/theme/siteTheme';

export default function SitesScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const theme = buildSiteTheme();
  const { sites, activeSiteId, setActiveSite, removeSite } = useSites();

  return (
    <View style={[styles.root, { backgroundColor: theme.background, paddingTop: insets.top + spacing.md }]}>
      <View style={styles.header}>
        <PageTitle theme={theme}>Your sites</PageTitle>
        <Pressable
          accessibilityRole="button"
          onPress={() => router.push('/sites/add')}
          style={({ pressed }) => [
            styles.addBtn,
            { backgroundColor: theme.accent, opacity: pressed ? 0.88 : 1 },
          ]}
        >
          <Text style={[styles.addBtnText, { color: theme.onAccent }]}>Add site</Text>
        </Pressable>
      </View>

      <ScreenScroll contentStyle={styles.list} theme={theme}>
        {sites.length === 0 ? (
          <View style={[styles.emptyWrap, { backgroundColor: theme.surfaceElevated, borderColor: theme.border }]}>
            <Ionicons color={theme.textMuted} name="globe-outline" size={36} />
            <BodyText muted theme={theme}>
              No sites yet. Add your Struxa site URL to get started.
            </BodyText>
          </View>
        ) : (
          sites.map((site) => {
            const active = site.id === activeSiteId;
            return (
              <Card highlighted={active} key={site.id} theme={theme}>
                <Pressable
                  accessibilityRole="button"
                  onPress={async () => {
                    await setActiveSite(site.id);
                    router.replace(`/s/${site.id}`);
                  }}
                  style={({ pressed }) => [{ opacity: pressed ? 0.85 : 1 }]}
                >
                  <Text style={[styles.siteName, { color: theme.text }]}>{site.label}</Text>
                  <Text style={[styles.siteUrl, { color: theme.textMuted }]}>{site.url}</Text>
                  {active ? (
                    <View style={[styles.activePill, { backgroundColor: theme.accentSoft }]}>
                      <Text style={[styles.activePillText, { color: theme.accent }]}>Active</Text>
                    </View>
                  ) : null}
                </Pressable>
                <Pressable
                  accessibilityRole="button"
                  onPress={() => removeSite(site.id)}
                  style={({ pressed }) => [styles.removeBtn, { opacity: pressed ? 0.7 : 1 }]}
                >
                  <Text style={[styles.removeText, { color: theme.danger }]}>Remove</Text>
                </Pressable>
              </Card>
            );
          })
        )}
      </ScreenScroll>
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    paddingHorizontal: spacing.lg,
  },
  header: {
    alignItems: 'center',
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: spacing.sm,
  },
  addBtn: {
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 10,
  },
  addBtnText: {
    fontSize: 14,
    fontWeight: '700',
  },
  list: {
    paddingHorizontal: 0,
    paddingTop: spacing.sm,
  },
  emptyWrap: {
    alignItems: 'center',
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.lg,
    gap: spacing.sm,
    padding: spacing.xl,
  },
  siteName: {
    fontSize: 18,
    fontWeight: '700',
  },
  siteUrl: {
    fontSize: 13,
    marginTop: 2,
  },
  activePill: {
    alignSelf: 'flex-start',
    borderRadius: radius.pill,
    marginTop: spacing.sm,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  activePillText: {
    fontSize: 11,
    fontWeight: '800',
    letterSpacing: 0.6,
    textTransform: 'uppercase',
  },
  removeBtn: {
    alignSelf: 'flex-start',
    marginTop: spacing.sm,
  },
  removeText: {
    fontSize: 13,
    fontWeight: '600',
  },
});
