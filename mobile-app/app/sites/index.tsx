import { useRouter } from 'expo-router';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useSites } from '../../src/context/SitesContext';
import { buildSiteTheme } from '../../src/theme/siteTheme';

export default function SitesScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const theme = buildSiteTheme();
  const { sites, activeSiteId, setActiveSite, removeSite } = useSites();

  return (
    <View style={[styles.root, { backgroundColor: theme.background, paddingTop: insets.top + 12 }]}>
      <View style={styles.header}>
        <Text style={[styles.title, { color: theme.text }]}>Your sites</Text>
        <Pressable
          accessibilityRole="button"
          onPress={() => router.push('/sites/add')}
          style={({ pressed }) => [
            styles.addBtn,
            { backgroundColor: theme.accent, opacity: pressed ? 0.85 : 1 },
          ]}
        >
          <Text style={styles.addBtnText}>Add site</Text>
        </Pressable>
      </View>

      <ScrollView contentContainerStyle={styles.list}>
        {sites.length === 0 ? (
          <Text style={[styles.empty, { color: theme.textMuted }]}>
            No sites yet. Add your Struxa site URL to get started.
          </Text>
        ) : (
          sites.map((site) => {
            const active = site.id === activeSiteId;
            return (
              <View
                key={site.id}
                style={[
                  styles.card,
                  {
                    backgroundColor: theme.surface,
                    borderColor: active ? theme.accent : theme.border,
                  },
                ]}
              >
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
                    <Text style={[styles.activePill, { color: theme.accent }]}>Active</Text>
                  ) : null}
                </Pressable>
                <Pressable
                  accessibilityRole="button"
                  onPress={() => removeSite(site.id)}
                  style={({ pressed }) => [styles.removeBtn, { opacity: pressed ? 0.7 : 1 }]}
                >
                  <Text style={[styles.removeText, { color: theme.textMuted }]}>Remove</Text>
                </Pressable>
              </View>
            );
          })
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    paddingHorizontal: 20,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 16,
  },
  title: {
    fontSize: 28,
    fontWeight: '800',
  },
  addBtn: {
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 10,
  },
  addBtnText: {
    color: '#fff',
    fontWeight: '700',
  },
  list: {
    gap: 12,
    paddingBottom: 32,
  },
  empty: {
    fontSize: 15,
    lineHeight: 22,
  },
  card: {
    borderWidth: 1,
    borderRadius: 16,
    padding: 16,
    gap: 10,
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
    marginTop: 8,
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  removeBtn: {
    alignSelf: 'flex-start',
  },
  removeText: {
    fontSize: 13,
    fontWeight: '600',
  },
});
