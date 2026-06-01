import { StyleSheet, Text, View } from 'react-native';

import type { BootstrapData, MobileTab } from '../types/bootstrap';
import type { SiteTheme } from '../theme/siteTheme';
import { ContentBrowser } from './content/ContentBrowser';
import { AccountScreen } from './account/AccountScreen';
import { ShopBrowser } from './shop/ShopBrowser';
import { WebTabScreen } from './WebTabScreen';
import { BodyText, Card, Eyebrow, FeatureChip, HeroCard, ScreenScroll, SectionTitle } from './ui/primitives';

type Props = {
  bootstrap: BootstrapData;
  tab: MobileTab;
  theme: SiteTheme;
  siteId: string;
};

export function TabScreen({ bootstrap, tab, theme, siteId }: Props) {
  switch (tab.type) {
    case 'home':
      return <HomeScreen bootstrap={bootstrap} theme={theme} />;
    case 'content':
      return (
        <ContentBrowser
          bootstrap={bootstrap}
          initialTypeSlug={tab.content_type_slug}
          theme={theme}
        />
      );
    case 'account':
      return <AccountScreen bootstrap={bootstrap} siteId={siteId} theme={theme} />;
    case 'search':
      return <SearchScreen bootstrap={bootstrap} theme={theme} />;
    case 'shop':
      return <ShopBrowser bootstrap={bootstrap} siteId={siteId} theme={theme} />;
    case 'link':
    case 'plugin':
      return <WebTabScreen bootstrap={bootstrap} tab={tab} theme={theme} />;
    default:
      return <PlaceholderScreen bootstrap={bootstrap} tab={tab} theme={theme} />;
  }
}

function HomeScreen({ bootstrap, theme }: Omit<Props, 'tab' | 'siteId'>) {
  return (
    <ScreenScroll theme={theme}>
      <HeroCard
        message={bootstrap.mobile.welcome_message || bootstrap.site.tagline || undefined}
        theme={theme}
        title={bootstrap.mobile.welcome_title}
      />

      <View style={styles.chipRow}>
        <FeatureChip label="CMS" theme={theme} value={`v${bootstrap.cms_version}`} />
        {bootstrap.features.commerce ? (
          <FeatureChip
            label="Shop"
            theme={theme}
            value={(bootstrap.commerce?.currency ?? '—').toUpperCase()}
          />
        ) : (
          <FeatureChip label="Sites" theme={theme} value={bootstrap.site.language.toUpperCase()} />
        )}
      </View>

      {bootstrap.content_types.length > 0 ? (
        <>
          <SectionTitle theme={theme}>On this site</SectionTitle>
          {bootstrap.content_types.map((type) => (
            <Card key={type.slug} theme={theme}>
              <Eyebrow theme={theme}>{type.route}</Eyebrow>
              <Text style={[styles.typeName, { color: theme.text }]}>{type.name}</Text>
              {type.description ? (
                <BodyText muted theme={theme}>
                  {type.description}
                </BodyText>
              ) : null}
            </Card>
          ))}
          <BodyText muted theme={theme}>
            Open Browse to read published entries, or Shop if this site sells products.
          </BodyText>
        </>
      ) : null}
    </ScreenScroll>
  );
}

function SearchScreen({ bootstrap, theme }: Omit<Props, 'tab' | 'siteId'>) {
  const enabled = bootstrap.features.search;
  return (
    <ScreenScroll theme={theme}>
      <SectionTitle theme={theme}>Search</SectionTitle>
      <Card theme={theme}>
        <BodyText theme={theme}>
          {enabled
            ? 'Search is enabled on this site. Full in-app search is coming in a future update.'
            : 'Search is not enabled on this site.'}
        </BodyText>
      </Card>
    </ScreenScroll>
  );
}

function PlaceholderScreen({ bootstrap, tab, theme }: Omit<Props, 'siteId'>) {
  return (
    <ScreenScroll theme={theme}>
      <SectionTitle theme={theme}>{tab.label}</SectionTitle>
      <Card theme={theme}>
        <BodyText theme={theme}>
          Custom tab{' '}
          <Text style={{ color: theme.accent, fontWeight: '700' }}>{tab.type}</Text> for {bootstrap.site.name}.
        </BodyText>
      </Card>
    </ScreenScroll>
  );
}

const styles = StyleSheet.create({
  chipRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
  },
  typeName: {
    fontSize: 18,
    fontWeight: '700',
  },
});
