import { ScrollView, StyleSheet, Text, View } from 'react-native';

import type { BootstrapData, MobileTab } from '../types/bootstrap';
import type { SiteTheme } from '../theme/siteTheme';
import { ContentBrowser } from './content/ContentBrowser';

type Props = {
  bootstrap: BootstrapData;
  tab: MobileTab;
  theme: SiteTheme;
};

export function TabScreen({ bootstrap, tab, theme }: Props) {
  switch (tab.type) {
    case 'home':
      return <HomeScreen bootstrap={bootstrap} theme={theme} />;
    case 'content':
      return <ContentBrowser bootstrap={bootstrap} theme={theme} />;
    case 'search':
      return <SearchScreen bootstrap={bootstrap} theme={theme} />;
    case 'shop':
      return <ShopScreen bootstrap={bootstrap} theme={theme} />;
    default:
      return <PlaceholderScreen bootstrap={bootstrap} tab={tab} theme={theme} />;
  }
}

function ScreenShell({
  theme,
  title,
  children,
}: {
  theme: SiteTheme;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <ScrollView
      contentContainerStyle={styles.content}
      style={[styles.scroll, { backgroundColor: theme.background }]}
    >
      <Text style={[styles.title, { color: theme.text }]}>{title}</Text>
      {children}
    </ScrollView>
  );
}

function HomeScreen({ bootstrap, theme }: Omit<Props, 'tab'>) {
  return (
    <ScreenShell theme={theme} title={bootstrap.mobile.welcome_title}>
      {bootstrap.mobile.welcome_message ? (
        <Text style={[styles.lead, { color: theme.textMuted }]}>{bootstrap.mobile.welcome_message}</Text>
      ) : null}
      <View style={[styles.card, { backgroundColor: theme.surface, borderColor: theme.border }]}>
        <Text style={[styles.cardLabel, { color: theme.textMuted }]}>Powered by Struxa</Text>
        <Text style={[styles.cardValue, { color: theme.text }]}>{bootstrap.cms_version}</Text>
      </View>
      {bootstrap.content_types.length > 0 ? (
        <>
          <Text style={[styles.sectionTitle, { color: theme.text }]}>Content on this site</Text>
          {bootstrap.content_types.map((type) => (
            <View
              key={type.slug}
              style={[styles.card, { backgroundColor: theme.surface, borderColor: theme.border }]}
            >
              <Text style={[styles.cardValue, { color: theme.text }]}>{type.name}</Text>
              <Text style={[styles.cardLabel, { color: theme.textMuted }]}>{type.route}</Text>
            </View>
          ))}
          <Text style={[styles.phaseNote, { color: theme.textMuted }]}>
            Open the Browse tab to read published entries.
          </Text>
        </>
      ) : null}
    </ScreenShell>
  );
}

function SearchScreen({ bootstrap, theme }: Omit<Props, 'tab'>) {
  const enabled = bootstrap.features.search;
  return (
    <ScreenShell theme={theme} title="Search">
      <Text style={[styles.lead, { color: theme.textMuted }]}>
        {enabled
          ? 'Storefront search is enabled on this site.'
          : 'Search is not enabled on this site.'}
      </Text>
      <Text style={[styles.phaseNote, { color: theme.textMuted }]}>
        Phase 4+ can wire the public search page into this tab.
      </Text>
    </ScreenShell>
  );
}

function ShopScreen({ bootstrap, theme }: Omit<Props, 'tab'>) {
  const enabled = bootstrap.features.commerce;
  const shopTitle = bootstrap.commerce?.shop_title ?? 'Shop';
  return (
    <ScreenShell theme={theme} title={shopTitle}>
      <Text style={[styles.lead, { color: theme.textMuted }]}>
        {enabled
          ? `Commerce is enabled${bootstrap.commerce?.currency ? ` (${bootstrap.commerce.currency.toUpperCase()})` : ''}.`
          : 'Commerce is not enabled on this site.'}
      </Text>
      {enabled && bootstrap.commerce?.shop_path ? (
        <View style={[styles.card, { backgroundColor: theme.surface, borderColor: theme.border }]}>
          <Text style={[styles.cardLabel, { color: theme.textMuted }]}>Shop path</Text>
          <Text style={[styles.cardValue, { color: theme.text }]}>{bootstrap.commerce.shop_path}</Text>
        </View>
      ) : null}
      <Text style={[styles.phaseNote, { color: theme.textMuted }]}>
        Phase 5 will show products and checkout in the app.
      </Text>
    </ScreenShell>
  );
}

function PlaceholderScreen({ bootstrap, tab, theme }: Props) {
  return (
    <ScreenShell theme={theme} title={tab.label}>
      <Text style={[styles.lead, { color: theme.textMuted }]}>
        Custom tab type <Text style={{ color: theme.accent }}>{tab.type}</Text> for {bootstrap.site.name}.
      </Text>
    </ScreenShell>
  );
}

const styles = StyleSheet.create({
  scroll: {
    flex: 1,
  },
  content: {
    padding: 20,
    paddingBottom: 32,
    gap: 12,
  },
  title: {
    fontSize: 28,
    fontWeight: '800',
    marginBottom: 4,
  },
  lead: {
    fontSize: 16,
    lineHeight: 24,
  },
  sectionTitle: {
    fontSize: 18,
    fontWeight: '700',
    marginTop: 8,
  },
  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 16,
    padding: 16,
    gap: 4,
  },
  cardLabel: {
    fontSize: 12,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  cardValue: {
    fontSize: 18,
    fontWeight: '700',
  },
  phaseNote: {
    fontSize: 13,
    lineHeight: 20,
    marginTop: 8,
  },
});
