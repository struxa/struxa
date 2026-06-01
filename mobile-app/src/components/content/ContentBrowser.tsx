import { useCallback, useEffect, useState } from 'react';
import { Image } from 'expo-image';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { fetchEntryDetail, fetchEntryList, formatDate, stripHtml } from '../../lib/content';
import { radius, spacing } from '../../theme/layout';
import type { BootstrapData } from '../../types/bootstrap';
import type { EntryDetailPayload, EntrySummary } from '../../types/content';
import type { SiteTheme } from '../../theme/siteTheme';
import { ErrorView } from '../StatusViews';
import { BackLink, BodyText, Card, Eyebrow, PageTitle } from '../ui/primitives';

type Props = {
  bootstrap: BootstrapData;
  theme: SiteTheme;
  initialTypeSlug?: string;
};

type ViewState =
  | { kind: 'types' }
  | { kind: 'list'; typeSlug: string; typeName: string }
  | { kind: 'detail'; typeSlug: string; typeName: string; entrySlug: string };

function resolveInitialView(
  bootstrap: BootstrapData,
  initialTypeSlug?: string,
): ViewState {
  if (!initialTypeSlug) {
    return { kind: 'types' };
  }
  const match = bootstrap.content_types.find((type) => type.slug === initialTypeSlug);
  if (!match) {
    return { kind: 'types' };
  }
  return { kind: 'list', typeSlug: match.slug, typeName: match.name };
}

export function ContentBrowser({ bootstrap, theme, initialTypeSlug }: Props) {
  const [view, setView] = useState<ViewState>(() => resolveInitialView(bootstrap, initialTypeSlug));
  const siteOrigin = bootstrap.site.url.replace(/\/+$/, '');

  const goBack = () => {
    if (view.kind === 'detail') {
      setView({ kind: 'list', typeSlug: view.typeSlug, typeName: view.typeName });
      return;
    }
    if (view.kind === 'list') {
      setView({ kind: 'types' });
    }
  };

  return (
    <View style={[styles.root, { backgroundColor: theme.background }]}>
      {view.kind !== 'types' && !initialTypeSlug ? (
        <View style={styles.backWrap}>
          <BackLink onPress={goBack} theme={theme} />
        </View>
      ) : null}

      {view.kind === 'types' ? (
        <ContentTypeList
          bootstrap={bootstrap}
          onSelect={(typeSlug, typeName) => setView({ kind: 'list', typeSlug, typeName })}
          theme={theme}
        />
      ) : null}

      {view.kind === 'list' ? (
        <EntryListView
          onOpenEntry={(entrySlug) =>
            setView({
              kind: 'detail',
              typeSlug: view.typeSlug,
              typeName: view.typeName,
              entrySlug,
            })
          }
          siteOrigin={siteOrigin}
          theme={theme}
          typeName={view.typeName}
          typeSlug={view.typeSlug}
        />
      ) : null}

      {view.kind === 'detail' ? (
        <EntryDetailView
          entrySlug={view.entrySlug}
          siteOrigin={siteOrigin}
          theme={theme}
          typeSlug={view.typeSlug}
        />
      ) : null}
    </View>
  );
}

function ContentTypeList({
  bootstrap,
  theme,
  onSelect,
}: {
  bootstrap: BootstrapData;
  theme: SiteTheme;
  onSelect: (typeSlug: string, typeName: string) => void;
}) {
  return (
    <FlatList
      contentContainerStyle={styles.listContent}
      data={bootstrap.content_types}
      keyExtractor={(item) => item.slug}
      ListHeaderComponent={<PageTitle theme={theme}>Browse content</PageTitle>}
      ListEmptyComponent={
        <BodyText muted theme={theme}>No public content types on this site.</BodyText>
      }
      renderItem={({ item }) => (
        <Card onPress={() => onSelect(item.slug, item.name)} theme={theme}>
          <Text style={[styles.cardTitle, { color: theme.text }]}>{item.name}</Text>
          {item.description ? (
            <BodyText muted theme={theme}>{item.description}</BodyText>
          ) : null}
        </Card>
      )}
    />
  );
}

function EntryListView({
  siteOrigin,
  typeSlug,
  typeName,
  theme,
  onOpenEntry,
}: {
  siteOrigin: string;
  typeSlug: string;
  typeName: string;
  theme: SiteTheme;
  onOpenEntry: (entrySlug: string) => void;
}) {
  const [items, setItems] = useState<EntrySummary[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadPage = useCallback(
    async (nextPage: number, append: boolean) => {
      if (append) {
        setLoadingMore(true);
      } else {
        setLoading(true);
      }
      setError(null);
      try {
        const response = await fetchEntryList(siteOrigin, typeSlug, nextPage);
        setTotalPages(response.meta.total_pages);
        setPage(response.meta.page);
        setItems((prev) => (append ? [...prev, ...response.data] : response.data));
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load entries.');
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    [siteOrigin, typeSlug],
  );

  useEffect(() => {
    void loadPage(1, false);
  }, [loadPage]);

  if (loading && items.length === 0) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={theme.accent} size="large" />
      </View>
    );
  }

  if (error && items.length === 0) {
    return <ErrorView message={error} onRetry={() => void loadPage(1, false)} theme={theme} />;
  }

  return (
    <FlatList
      contentContainerStyle={styles.listContent}
      data={items}
      keyExtractor={(item) => String(item.id)}
      ListHeaderComponent={<PageTitle theme={theme}>{typeName}</PageTitle>}
      ListEmptyComponent={
        <BodyText muted theme={theme}>No published entries yet.</BodyText>
      }
      ListFooterComponent={
        page < totalPages ? (
          <Pressable
            accessibilityRole="button"
            disabled={loadingMore}
            onPress={() => void loadPage(page + 1, true)}
            style={({ pressed }) => [
              styles.loadMore,
              {
                borderColor: theme.border,
                opacity: pressed || loadingMore ? 0.7 : 1,
              },
            ]}
          >
            {loadingMore ? (
              <ActivityIndicator color={theme.accent} />
            ) : (
              <Text style={[styles.loadMoreText, { color: theme.accent }]}>Load more</Text>
            )}
          </Pressable>
        ) : null
      }
      renderItem={({ item }) => (
        <Card onPress={() => onOpenEntry(item.slug)} theme={theme}>
          {item.featured_image_url ? (
            <Image
              contentFit="cover"
              source={{ uri: item.featured_image_url }}
              style={styles.thumb}
            />
          ) : null}
          <Text style={[styles.cardTitle, { color: theme.text }]}>{item.title}</Text>
          {item.published_at ? (
            <Eyebrow theme={theme}>{formatDate(item.published_at)}</Eyebrow>
          ) : null}
          {item.excerpt ? (
            <Text numberOfLines={3} style={[styles.cardBody, { color: theme.textMuted }]}>
              {item.excerpt}
            </Text>
          ) : null}
        </Card>
      )}
    />
  );
}

function EntryDetailView({
  siteOrigin,
  typeSlug,
  entrySlug,
  theme,
}: {
  siteOrigin: string;
  typeSlug: string;
  entrySlug: string;
  theme: SiteTheme;
}) {
  const [detail, setDetail] = useState<EntryDetailPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    fetchEntryDetail(siteOrigin, typeSlug, entrySlug)
      .then((response) => {
        if (!cancelled) {
          setDetail(response.data);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load entry.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [entrySlug, siteOrigin, typeSlug]);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={theme.accent} size="large" />
      </View>
    );
  }

  if (error || !detail) {
    return (
      <ErrorView
        message={error ?? 'Entry not found.'}
        onRetry={() => {
          setLoading(true);
          fetchEntryDetail(siteOrigin, typeSlug, entrySlug)
            .then((response) => setDetail(response.data))
            .catch((err) => setError(err instanceof Error ? err.message : 'Could not load entry.'))
            .finally(() => setLoading(false));
        }}
        theme={theme}
      />
    );
  }

  const { entry, fields, taxonomies } = detail;
  const bodyFields = fields.filter((field) => {
    const value = field.html || field.value;
    return value.trim() !== '';
  });

  return (
    <FlatList
      contentContainerStyle={styles.listContent}
      data={bodyFields}
      keyExtractor={(field) => field.key}
      ListHeaderComponent={
        <View style={styles.detailHeader}>
          {entry.featured_image_url ? (
            <Image
              contentFit="cover"
              source={{ uri: entry.featured_image_url }}
              style={styles.hero}
            />
          ) : null}
          <Text style={[styles.detailTitle, { color: theme.text }]}>{entry.title}</Text>
          {entry.published_at ? (
            <Eyebrow theme={theme}>{formatDate(entry.published_at)}</Eyebrow>
          ) : null}
          {entry.seo_description ? (
            <BodyText muted theme={theme}>{entry.seo_description}</BodyText>
          ) : null}
          {taxonomies.length > 0 ? (
            <View style={styles.tagRow}>
              {taxonomies.flatMap((group) =>
                group.terms.map((term) => (
                  <View
                    key={`${group.slug}-${term.slug}`}
                    style={[styles.tag, { backgroundColor: theme.accentSoft }]}
                  >
                    <Text style={[styles.tagText, { color: theme.accent }]}>{term.name}</Text>
                  </View>
                )),
              )}
            </View>
          ) : null}
        </View>
      }
      renderItem={({ item }) => {
        const text = item.html ? stripHtml(item.html) : item.value.trim();
        if (text === '') {
          return null;
        }
        return (
          <View style={[styles.fieldBlock, { borderColor: theme.border }]}>
            <Text style={[styles.fieldLabel, { color: theme.textMuted }]}>{item.label}</Text>
            <Text style={[styles.fieldValue, { color: theme.text }]}>{text}</Text>
          </View>
        );
      }}
    />
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
  },
  backWrap: {
    paddingBottom: spacing.xs,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.sm,
  },
  listContent: {
    gap: spacing.md,
    padding: spacing.md,
    paddingBottom: spacing.xl,
  },
  cardTitle: {
    fontSize: 17,
    fontWeight: '700',
  },
  cardBody: {
    fontSize: 14,
    lineHeight: 20,
  },
  thumb: {
    borderRadius: radius.md,
    height: 160,
    marginBottom: spacing.xs,
    width: '100%',
  },
  center: {
    alignItems: 'center',
    flex: 1,
    justifyContent: 'center',
  },
  loadMore: {
    alignItems: 'center',
    borderRadius: radius.md,
    borderWidth: StyleSheet.hairlineWidth,
    marginTop: spacing.xs,
    paddingVertical: 12,
  },
  loadMoreText: {
    fontWeight: '700',
  },
  detailHeader: {
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  hero: {
    borderRadius: radius.lg,
    height: 220,
    width: '100%',
  },
  detailTitle: {
    fontSize: 28,
    fontWeight: '800',
    letterSpacing: -0.4,
  },
  tagRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
  },
  tag: {
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  tagText: {
    fontSize: 12,
    fontWeight: '600',
  },
  fieldBlock: {
    borderTopWidth: StyleSheet.hairlineWidth,
    gap: 6,
    paddingTop: 12,
  },
  fieldLabel: {
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.6,
    textTransform: 'uppercase',
  },
  fieldValue: {
    fontSize: 15,
    lineHeight: 22,
  },
});
