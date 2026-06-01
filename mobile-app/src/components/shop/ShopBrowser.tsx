import { useCallback, useEffect, useState } from 'react';
import { Image } from 'expo-image';
import { Ionicons } from '@expo/vector-icons';
import * as Linking from 'expo-linking';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import {
  fetchProductDetail,
  fetchProductList,
  quoteCart,
  startCheckout,
} from '../../lib/commerce';
import {
  cartCount,
  clearSiteCart,
  loadSiteCart,
  saveSiteCart,
  toCartLineInput,
  upsertCartLine,
} from '../../lib/cartStorage';
import { loadSiteAuth } from '../../lib/authStorage';
import type { BootstrapData } from '../../types/bootstrap';
import type { CartQuote, ProductDetail, ProductSummary, StoredCartLine } from '../../types/commerce';
import type { SiteTheme } from '../../theme/siteTheme';
import { radius, spacing } from '../../theme/layout';
import { ErrorView } from '../StatusViews';
import { BackLink, BodyText, Card, Eyebrow, PrimaryButton, SectionTitle } from '../ui/primitives';

type Props = {
  bootstrap: BootstrapData;
  siteId: string;
  theme: SiteTheme;
};

type ViewState =
  | { kind: 'list' }
  | { kind: 'detail'; entrySlug: string }
  | { kind: 'cart' };

export function ShopBrowser({ bootstrap, siteId, theme }: Props) {
  const siteOrigin = bootstrap.site.url.replace(/\/+$/, '');
  const shopTitle = bootstrap.commerce?.shop_title ?? 'Shop';
  const needsCountry = bootstrap.commerce?.needs_checkout_country ?? false;

  const [view, setView] = useState<ViewState>({ kind: 'list' });
  const [cartLines, setCartLines] = useState<StoredCartLine[]>([]);
  const [cartQuote, setCartQuote] = useState<CartQuote | null>(null);
  const [shipCountry, setShipCountry] = useState('');
  const [couponCode, setCouponCode] = useState('');
  const [checkoutBusy, setCheckoutBusy] = useState(false);
  const [checkoutError, setCheckoutError] = useState<string | null>(null);

  const refreshCart = useCallback(async () => {
    const lines = await loadSiteCart(siteId);
    setCartLines(lines);
    if (lines.length === 0) {
      setCartQuote(null);
      return;
    }
    try {
      const quote = await quoteCart(
        siteOrigin,
        toCartLineInput(lines),
        shipCountry || null,
        couponCode || null,
      );
      setCartQuote(quote.data);
      if (quote.data.ship_country) {
        setShipCountry(quote.data.ship_country);
      }
    } catch {
      setCartQuote(null);
    }
  }, [couponCode, shipCountry, siteId, siteOrigin]);

  useEffect(() => {
    void loadSiteCart(siteId).then(setCartLines);
  }, [siteId]);

  useEffect(() => {
    if (view.kind === 'cart') {
      void refreshCart();
    }
  }, [refreshCart, view.kind, cartLines]);

  const addToCart = async (entryId: number, quantity = 1) => {
    const next = upsertCartLine(cartLines, entryId, (cartLines.find((l) => l.entryId === entryId)?.quantity ?? 0) + quantity);
    setCartLines(next);
    await saveSiteCart(siteId, next);
  };

  const setLineQuantity = async (entryId: number, quantity: number) => {
    const next = upsertCartLine(cartLines, entryId, quantity);
    setCartLines(next);
    await saveSiteCart(siteId, next);
  };

  const onCheckout = async () => {
    if (cartLines.length === 0) {
      return;
    }
    setCheckoutBusy(true);
    setCheckoutError(null);
    try {
      const auth = await loadSiteAuth(siteId);
      const result = await startCheckout(
        siteOrigin,
        toCartLineInput(cartLines),
        shipCountry || null,
        couponCode || null,
        auth?.accessToken ?? null,
      );
      await Linking.openURL(result.data.checkout_url);
    } catch (err) {
      setCheckoutError(err instanceof Error ? err.message : 'Checkout failed.');
    } finally {
      setCheckoutBusy(false);
    }
  };

  const badge = cartCount(cartLines);

  const goBack = () => {
    if (view.kind === 'detail') {
      setView({ kind: 'list' });
    } else if (view.kind === 'cart') {
      setView({ kind: 'list' });
    }
  };

  if (!bootstrap.features.commerce) {
    return (
      <View style={[styles.center, { backgroundColor: theme.background }]}>
        <Ionicons color={theme.textMuted} name="cart-outline" size={40} />
        <BodyText muted theme={theme}>Commerce is not enabled on this site.</BodyText>
      </View>
    );
  }

  return (
    <View style={[styles.root, { backgroundColor: theme.background }]}>
      {view.kind !== 'list' ? (
        <View style={styles.backWrap}>
          <BackLink onPress={goBack} theme={theme} />
        </View>
      ) : (
        <View style={styles.listHeader}>
          <SectionTitle theme={theme}>{shopTitle}</SectionTitle>
          <Pressable
            accessibilityRole="button"
            onPress={() => setView({ kind: 'cart' })}
            style={({ pressed }) => [
              styles.cartButton,
              { backgroundColor: theme.surfaceElevated, borderColor: theme.border, opacity: pressed ? 0.85 : 1 },
            ]}
          >
            <Ionicons color={theme.text} name="cart-outline" size={20} />
            {badge > 0 ? (
              <View style={[styles.badge, { backgroundColor: theme.accent }]}>
                <Text style={[styles.badgeText, { color: theme.onAccent }]}>{badge}</Text>
              </View>
            ) : null}
          </Pressable>
        </View>
      )}

      {view.kind === 'list' ? (
        <ProductListView
          onOpenProduct={(entrySlug) => setView({ kind: 'detail', entrySlug })}
          siteOrigin={siteOrigin}
          theme={theme}
        />
      ) : null}

      {view.kind === 'detail' ? (
        <ProductDetailView
          entrySlug={view.entrySlug}
          onAddToCart={(entryId) => void addToCart(entryId)}
          siteOrigin={siteOrigin}
          theme={theme}
        />
      ) : null}

      {view.kind === 'cart' ? (
        <CartView
          cartQuote={cartQuote}
          checkoutBusy={checkoutBusy}
          checkoutError={checkoutError}
          couponCode={couponCode}
          needsCountry={needsCountry}
          onApplyCoupon={() => void refreshCart()}
          onCheckout={() => void onCheckout()}
          onClear={() => {
            void clearSiteCart(siteId).then(() => {
              setCartLines([]);
              setCartQuote(null);
            });
          }}
          onSetQuantity={(entryId, qty) => void setLineQuantity(entryId, qty)}
          shipCountry={shipCountry}
          setCouponCode={setCouponCode}
          setShipCountry={setShipCountry}
          theme={theme}
        />
      ) : null}
    </View>
  );
}

function ProductListView({
  siteOrigin,
  theme,
  onOpenProduct,
}: {
  siteOrigin: string;
  theme: SiteTheme;
  onOpenProduct: (entrySlug: string) => void;
}) {
  const [items, setItems] = useState<ProductSummary[]>([]);
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
        const response = await fetchProductList(siteOrigin, nextPage);
        setTotalPages(response.meta.total_pages);
        setPage(response.meta.page);
        setItems((prev) => (append ? [...prev, ...response.data] : response.data));
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load products.');
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    [siteOrigin],
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
      ListEmptyComponent={<BodyText muted theme={theme}>No products available yet.</BodyText>}
      ListFooterComponent={
        page < totalPages ? (
          <Pressable
            accessibilityRole="button"
            disabled={loadingMore}
            onPress={() => void loadPage(page + 1, true)}
            style={({ pressed }) => [
              styles.loadMore,
              { borderColor: theme.border, opacity: pressed || loadingMore ? 0.7 : 1 },
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
        <Card onPress={() => onOpenProduct(item.slug)} theme={theme}>
          {item.featured_image_url ? (
            <Image contentFit="cover" source={{ uri: item.featured_image_url }} style={styles.thumb} />
          ) : null}
          <Text style={[styles.cardTitle, { color: theme.text }]}>{item.title}</Text>
          <Text style={[styles.price, { color: theme.accent }]}>{item.price_formatted}</Text>
          {!item.in_stock ? (
            <Eyebrow theme={theme}>Out of stock</Eyebrow>
          ) : null}
        </Card>
      )}
    />
  );
}

function ProductDetailView({
  siteOrigin,
  entrySlug,
  theme,
  onAddToCart,
}: {
  siteOrigin: string;
  entrySlug: string;
  theme: SiteTheme;
  onAddToCart: (entryId: number) => void;
}) {
  const [product, setProduct] = useState<ProductDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    fetchProductDetail(siteOrigin, entrySlug)
      .then((response) => {
        if (!cancelled) {
          setProduct(response.data);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load product.');
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
  }, [entrySlug, siteOrigin]);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={theme.accent} size="large" />
      </View>
    );
  }

  if (error || !product) {
    return (
      <ErrorView
        message={error ?? 'Product not found.'}
        onRetry={() => {
          setLoading(true);
          fetchProductDetail(siteOrigin, entrySlug)
            .then((response) => setProduct(response.data))
            .catch((err) => setError(err instanceof Error ? err.message : 'Could not load product.'))
            .finally(() => setLoading(false));
        }}
        theme={theme}
      />
    );
  }

  return (
    <FlatList
      contentContainerStyle={styles.listContent}
      data={[]}
      ListHeaderComponent={
        <View style={styles.detailHeader}>
          {product.featured_image_url ? (
            <Image contentFit="cover" source={{ uri: product.featured_image_url }} style={styles.hero} />
          ) : null}
          <Text style={[styles.detailTitle, { color: theme.text }]}>{product.title}</Text>
          <Text style={[styles.priceLarge, { color: theme.accent }]}>{product.price_formatted}</Text>
          {product.excerpt ? (
            <Text style={[styles.detailLead, { color: theme.textMuted }]}>{product.excerpt}</Text>
          ) : null}
          {!product.in_stock ? (
            <Eyebrow theme={theme}>Out of stock</Eyebrow>
          ) : (
            <PrimaryButton label="Add to cart" onPress={() => onAddToCart(product.id)} theme={theme} />
          )}
        </View>
      }
      renderItem={() => null}
    />
  );
}

function CartView({
  cartQuote,
  theme,
  needsCountry,
  shipCountry,
  setShipCountry,
  couponCode,
  setCouponCode,
  onApplyCoupon,
  onSetQuantity,
  onClear,
  onCheckout,
  checkoutBusy,
  checkoutError,
}: {
  cartQuote: CartQuote | null;
  theme: SiteTheme;
  needsCountry: boolean;
  shipCountry: string;
  setShipCountry: (value: string) => void;
  couponCode: string;
  setCouponCode: (value: string) => void;
  onApplyCoupon: () => void;
  onSetQuantity: (entryId: number, quantity: number) => void;
  onClear: () => void;
  onCheckout: () => void;
  checkoutBusy: boolean;
  checkoutError: string | null;
}) {
  if (!cartQuote || cartQuote.lines.length === 0) {
    return (
      <View style={[styles.center, styles.listContent]}>
        <Ionicons color={theme.textMuted} name="cart-outline" size={40} />
        <BodyText muted theme={theme}>Your cart is empty.</BodyText>
      </View>
    );
  }

  return (
    <FlatList
      contentContainerStyle={styles.listContent}
      data={cartQuote.lines}
      keyExtractor={(item) => String(item.entry_id)}
      ListFooterComponent={
        <View style={styles.cartFooter}>
          {needsCountry ? (
            <>
              <Text style={[styles.fieldLabel, { color: theme.textMuted }]}>Shipping country (ISO code)</Text>
              <TextInput
                autoCapitalize="characters"
                maxLength={2}
                onChangeText={setShipCountry}
                placeholder="GB"
                placeholderTextColor={theme.textMuted}
                style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surfaceElevated }]}
                value={shipCountry}
              />
            </>
          ) : null}

          <Text style={[styles.fieldLabel, { color: theme.textMuted }]}>Coupon code</Text>
          <View style={styles.couponRow}>
            <TextInput
              autoCapitalize="characters"
              onChangeText={setCouponCode}
              placeholder="Optional"
              placeholderTextColor={theme.textMuted}
              style={[
                styles.input,
                styles.couponInput,
                { color: theme.text, borderColor: theme.border, backgroundColor: theme.surfaceElevated },
              ]}
              value={couponCode}
            />
            <Pressable
              accessibilityRole="button"
              onPress={onApplyCoupon}
              style={({ pressed }) => [
                styles.secondaryButton,
                { borderColor: theme.border, opacity: pressed ? 0.85 : 1 },
              ]}
            >
              <Text style={[styles.secondaryButtonText, { color: theme.text }]}>Apply</Text>
            </Pressable>
          </View>
          {cartQuote.coupon_error ? (
            <Text style={[styles.errorText, { color: theme.danger }]}>{cartQuote.coupon_error}</Text>
          ) : null}

          <View style={[styles.totalsCard, { backgroundColor: theme.surfaceElevated, borderColor: theme.border }]}>
            <TotalRow label="Subtotal" theme={theme} value={formatCents(cartQuote.totals.subtotal_cents, cartQuote.currency)} />
            {cartQuote.totals.discount_cents > 0 ? (
              <TotalRow label="Discount" theme={theme} value={`-${formatCents(cartQuote.totals.discount_cents, cartQuote.currency)}`} />
            ) : null}
            {cartQuote.totals.tax_cents > 0 ? (
              <TotalRow label="Tax" theme={theme} value={formatCents(cartQuote.totals.tax_cents, cartQuote.currency)} />
            ) : null}
            {cartQuote.totals.shipping_cents > 0 ? (
              <TotalRow
                label={cartQuote.totals.shipping_label ?? 'Shipping'}
                theme={theme}
                value={formatCents(cartQuote.totals.shipping_cents, cartQuote.currency)}
              />
            ) : null}
            <TotalRow bold label="Total" theme={theme} value={cartQuote.totals.total_formatted} />
          </View>

          {checkoutError ? <Text style={[styles.errorText, { color: theme.danger }]}>{checkoutError}</Text> : null}

          <PrimaryButton
            disabled={checkoutBusy}
            label="Checkout with Stripe"
            loading={checkoutBusy}
            onPress={onCheckout}
            theme={theme}
          />

          <Pressable accessibilityRole="button" onPress={onClear} style={({ pressed }) => [{ opacity: pressed ? 0.7 : 1 }]}>
            <Text style={[styles.clearText, { color: theme.textMuted }]}>Clear cart</Text>
          </Pressable>

          <Text style={[styles.checkoutNote, { color: theme.textMuted }]}>
            Payment opens in your browser. Return here after completing checkout.
          </Text>
        </View>
      }
      ListHeaderComponent={<SectionTitle theme={theme}>Cart</SectionTitle>}
      renderItem={({ item }) => (
        <Card theme={theme}>
          <Text style={[styles.cardTitle, { color: theme.text }]}>{item.title}</Text>
          <Text style={[styles.price, { color: theme.accent }]}>{item.price_formatted}</Text>
          <View style={styles.qtyRow}>
            <Pressable
              accessibilityRole="button"
              onPress={() => onSetQuantity(item.entry_id, item.quantity - 1)}
              style={[styles.qtyButton, { backgroundColor: theme.surfaceOverlay, borderColor: theme.border }]}
            >
              <Text style={{ color: theme.text, fontSize: 18, fontWeight: '600' }}>−</Text>
            </Pressable>
            <Text style={[styles.qtyValue, { color: theme.text }]}>{item.quantity}</Text>
            <Pressable
              accessibilityRole="button"
              onPress={() => onSetQuantity(item.entry_id, item.quantity + 1)}
              style={[styles.qtyButton, { backgroundColor: theme.surfaceOverlay, borderColor: theme.border }]}
            >
              <Text style={{ color: theme.text, fontSize: 18, fontWeight: '600' }}>+</Text>
            </Pressable>
          </View>
        </Card>
      )}
    />
  );
}

function TotalRow({
  label,
  value,
  theme,
  bold = false,
}: {
  label: string;
  value: string;
  theme: SiteTheme;
  bold?: boolean;
}) {
  return (
    <View style={styles.totalRow}>
      <Text style={[styles.totalLabel, { color: theme.textMuted, fontWeight: bold ? '700' : '500' }]}>{label}</Text>
      <Text style={[styles.totalValue, { color: theme.text, fontWeight: bold ? '800' : '600' }]}>{value}</Text>
    </View>
  );
}

function formatCents(cents: number, currency: string): string {
  const amount = cents / 100;
  const symbol = currency.toLowerCase() === 'gbp' ? '£' : currency.toLowerCase() === 'eur' ? '€' : currency.toLowerCase() === 'usd' ? '$' : `${currency.toUpperCase()} `;
  return `${symbol}${amount.toFixed(2)}`;
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  center: { alignItems: 'center', flex: 1, gap: spacing.sm, justifyContent: 'center' },
  backWrap: { paddingBottom: spacing.xs, paddingHorizontal: spacing.md, paddingTop: spacing.sm },
  listHeader: {
    alignItems: 'center',
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingBottom: spacing.xs,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.sm,
  },
  cartButton: {
    alignItems: 'center',
    borderRadius: radius.pill,
    borderWidth: StyleSheet.hairlineWidth,
    height: 44,
    justifyContent: 'center',
    position: 'relative',
    width: 44,
  },
  badge: {
    alignItems: 'center',
    borderRadius: 10,
    height: 18,
    justifyContent: 'center',
    minWidth: 18,
    paddingHorizontal: 4,
    position: 'absolute',
    right: -4,
    top: -4,
  },
  badgeText: { fontSize: 10, fontWeight: '800' },
  listContent: { gap: spacing.md, padding: spacing.md, paddingBottom: spacing.xl },
  cardTitle: { fontSize: 17, fontWeight: '700' },
  price: { fontSize: 16, fontWeight: '700' },
  thumb: { borderRadius: radius.md, height: 160, marginBottom: spacing.xs, width: '100%' },
  hero: { borderRadius: radius.lg, height: 220, width: '100%' },
  detailHeader: { gap: spacing.sm, marginBottom: spacing.sm },
  detailTitle: { fontSize: 28, fontWeight: '800', letterSpacing: -0.4 },
  detailLead: { fontSize: 16, lineHeight: 24 },
  loadMore: {
    alignItems: 'center',
    borderRadius: radius.md,
    borderWidth: StyleSheet.hairlineWidth,
    marginTop: spacing.xs,
    paddingVertical: 12,
  },
  loadMoreText: { fontWeight: '700' },
  cartFooter: { gap: spacing.sm, marginTop: spacing.sm },
  fieldLabel: {
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.6,
    textTransform: 'uppercase',
  },
  input: {
    borderRadius: radius.md,
    borderWidth: 1,
    fontSize: 16,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
  },
  couponRow: { alignItems: 'center', flexDirection: 'row', gap: spacing.sm },
  couponInput: { flex: 1 },
  secondaryButton: {
    borderRadius: radius.md,
    borderWidth: StyleSheet.hairlineWidth,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
  },
  secondaryButtonText: { fontWeight: '700' },
  totalsCard: { borderRadius: radius.lg, borderWidth: StyleSheet.hairlineWidth, gap: spacing.sm, padding: spacing.md },
  totalRow: { flexDirection: 'row', justifyContent: 'space-between' },
  totalLabel: { fontSize: 14 },
  totalValue: { fontSize: 14 },
  qtyRow: { alignItems: 'center', flexDirection: 'row', gap: spacing.md, marginTop: spacing.xs },
  qtyButton: {
    alignItems: 'center',
    borderRadius: radius.sm,
    borderWidth: StyleSheet.hairlineWidth,
    height: 36,
    justifyContent: 'center',
    width: 36,
  },
  qtyValue: { fontSize: 16, fontWeight: '700', minWidth: 24, textAlign: 'center' },
  errorText: { fontSize: 14, lineHeight: 20 },
  clearText: { fontSize: 14, marginTop: spacing.xs, textAlign: 'center' },
  checkoutNote: { fontSize: 13, lineHeight: 18, textAlign: 'center' },
  priceLarge: { fontSize: 22, fontWeight: '800' },
});
