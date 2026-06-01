import { useCallback, useEffect, useState } from 'react';
import * as Linking from 'expo-linking';
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import {
  fetchMe,
  loginUser,
  logoutUser,
  refreshAuth,
  registerUser,
} from '../../lib/auth';
import { fetchDigitalDownloads, fetchOrderDetail, fetchOrders } from '../../lib/commerce';
import { BootstrapError } from '../../lib/bootstrap';
import {
  clearSiteAuth,
  isAccessTokenExpired,
  loadSiteAuth,
  saveSiteAuth,
  sessionFromAuthResponse,
} from '../../lib/authStorage';
import type { BootstrapData } from '../../types/bootstrap';
import type { SiteAuthSession } from '../../types/auth';
import type { DigitalDownload, OrderDetail, OrderSummary } from '../../types/commerce';
import type { SiteTheme } from '../../theme/siteTheme';
import { radius, spacing } from '../../theme/layout';
import {
  BodyText,
  Card,
  Eyebrow,
  FormError,
  PageTitle,
  PrimaryButton,
  ScreenScroll,
  SectionTitle,
} from '../ui/primitives';

type Props = {
  siteId: string;
  bootstrap: BootstrapData;
  theme: SiteTheme;
};

type Mode = 'login' | 'register';

export function AccountScreen({ siteId, bootstrap, theme }: Props) {
  const { api } = bootstrap;
  const siteOrigin = bootstrap.site.url.replace(/\/+$/, '');
  const collectUsername = bootstrap.features.auth.collect_username;
  const authReady = bootstrap.features.mobile_auth_ready !== false;

  const [session, setSession] = useState<SiteAuthSession | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState<Mode>('login');
  const [error, setError] = useState<string | null>(null);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [username, setUsername] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [needsTotp, setNeedsTotp] = useState(false);
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [downloads, setDownloads] = useState<DigitalDownload[]>([]);
  const [ordersLoading, setOrdersLoading] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState<OrderDetail | null>(null);
  const [orderDetailLoading, setOrderDetailLoading] = useState(false);

  const hydrate = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      let stored = await loadSiteAuth(siteId);
      if (stored && isAccessTokenExpired(stored)) {
        try {
          const refreshed = await refreshAuth(api.auth_refresh, stored.refreshToken);
          stored = sessionFromAuthResponse(refreshed);
          await saveSiteAuth(siteId, stored);
        } catch {
          await clearSiteAuth(siteId);
          stored = null;
        }
      }
      if (stored) {
        try {
          const user = await fetchMe(api.auth_me, stored.accessToken);
          stored = { ...stored, user };
          await saveSiteAuth(siteId, stored);
        } catch {
          // Keep the stored session — tokens may still work even if profile refresh failed.
        }
      }
      setSession(stored);
    } finally {
      setLoading(false);
    }
  }, [api.auth_me, api.auth_refresh, siteId]);

  useEffect(() => {
    void hydrate();
  }, [hydrate]);

  useEffect(() => {
    if (!session || !bootstrap.features.commerce) {
      setOrders([]);
      setDownloads([]);
      setSelectedOrder(null);
      return;
    }
    let cancelled = false;
    setOrdersLoading(true);
    Promise.all([
      fetchOrders(siteOrigin, session.accessToken),
      fetchDigitalDownloads(siteOrigin, session.accessToken),
    ])
      .then(([ordersResponse, downloadsResponse]) => {
        if (!cancelled) {
          setOrders(ordersResponse.data);
          setDownloads(downloadsResponse.data);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setOrders([]);
          setDownloads([]);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setOrdersLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [bootstrap.features.commerce, session, siteOrigin]);

  const showLoginError = (message: string) => {
    setError(message);
    Alert.alert('Sign in failed', message);
  };

  const onLogin = async () => {
    if (!authReady) {
      showLoginError(
        'Mobile sign-in is not set up on this site yet. Ask the admin to run database migrations (056_mobile_auth.sql).',
      );
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const data = await loginUser(api.auth_login, email, password, totpCode);
      let next = sessionFromAuthResponse(data);
      try {
        const user = await fetchMe(api.auth_me, next.accessToken);
        next = { ...next, user };
      } catch {
        // Login response already includes user; profile refresh is optional.
      }
      await saveSiteAuth(siteId, next);
      setSession(next);
      setNeedsTotp(false);
      setTotpCode('');
    } catch (err) {
      if (err instanceof BootstrapError && err.code === 'totp_required') {
        setNeedsTotp(true);
        showLoginError('Enter your authenticator code.');
      } else {
        showLoginError(err instanceof Error ? err.message : 'Login failed.');
      }
    } finally {
      setBusy(false);
    }
  };

  const onRegister = async () => {
    if (!authReady) {
      showLoginError(
        'Mobile sign-in is not set up on this site yet. Ask the admin to run database migrations (056_mobile_auth.sql).',
      );
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const data = await registerUser(
        api.auth_register,
        email,
        password,
        passwordConfirm || password,
        username,
      );
      if (!data.activated) {
        setError(data.message);
        setMode('login');
        return;
      }
      const next = sessionFromAuthResponse(data);
      await saveSiteAuth(siteId, next);
      setSession(next);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Registration failed.');
    } finally {
      setBusy(false);
    }
  };

  const openOrder = async (orderNumber: string) => {
    if (!session) {
      return;
    }
    setOrderDetailLoading(true);
    try {
      const response = await fetchOrderDetail(siteOrigin, session.accessToken, orderNumber);
      setSelectedOrder(response.data);
    } catch {
      setSelectedOrder(null);
    } finally {
      setOrderDetailLoading(false);
    }
  };

  const openDownload = (url: string) => {
    void Linking.openURL(url);
  };

  const onLogout = async () => {
    if (!session) {
      return;
    }
    setBusy(true);
    try {
      await logoutUser(api.auth_logout, session.refreshToken);
    } catch {
      // Clear local session even if server revoke fails.
    } finally {
      await clearSiteAuth(siteId);
      setSession(null);
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <View style={[styles.center, { backgroundColor: theme.background }]}>
        <ActivityIndicator color={theme.accent} size="large" />
      </View>
    );
  }

  if (session) {
    const user = session.user;
    return (
      <ScreenScroll theme={theme}>
        <PageTitle theme={theme}>Account</PageTitle>
        <Card theme={theme}>
          <Eyebrow theme={theme}>Signed in as</Eyebrow>
          <Text style={[styles.value, { color: theme.text }]}>{user.display_name ?? user.email}</Text>
          {user.username ? (
            <BodyText muted theme={theme}>@{user.username}</BodyText>
          ) : null}
          <BodyText muted theme={theme}>{user.email}</BodyText>
          {user.is_cms_staff ? (
            <Text style={[styles.badge, { color: theme.accent }]}>CMS staff on this site</Text>
          ) : null}
        </Card>
        <BodyText muted theme={theme}>
          Your session is stored only for {bootstrap.site.name}. Other sites you add keep separate accounts.
        </BodyText>
        {bootstrap.features.commerce ? (
          <>
            {downloads.length > 0 ? (
              <Card theme={theme}>
                <Eyebrow theme={theme}>Downloads</Eyebrow>
                {downloads.map((item) => (
                  <Pressable
                    key={`${item.order_number}-${item.id}`}
                    accessibilityRole="button"
                    onPress={() => openDownload(item.access_url)}
                    style={({ pressed }) => [styles.orderRow, { opacity: pressed ? 0.75 : 1 }]}
                  >
                    <Text style={[styles.linkValue, { color: theme.accent }]}>{item.label}</Text>
                    <BodyText muted theme={theme}>
                      {item.order_number} · {item.delivery_type}
                    </BodyText>
                  </Pressable>
                ))}
              </Card>
            ) : null}
            <Card theme={theme}>
              <Eyebrow theme={theme}>Order history</Eyebrow>
              {ordersLoading ? (
                <ActivityIndicator color={theme.accent} style={{ marginTop: spacing.sm }} />
              ) : orders.length === 0 ? (
                <BodyText muted theme={theme}>No orders yet.</BodyText>
              ) : (
                orders.slice(0, 10).map((order) => (
                  <Pressable
                    key={order.order_number}
                    accessibilityRole="button"
                    onPress={() => void openOrder(order.order_number)}
                    style={({ pressed }) => [styles.orderRow, { opacity: pressed ? 0.75 : 1 }]}
                  >
                    <Text style={[styles.orderTitle, { color: theme.text }]}>{order.order_number}</Text>
                    <BodyText muted theme={theme}>
                      {order.total_formatted} · {order.status}
                      {(order.download_count ?? 0) > 0 ? ` · ${order.download_count} download(s)` : ''}
                    </BodyText>
                  </Pressable>
                ))
              )}
            </Card>
            {orderDetailLoading ? <ActivityIndicator color={theme.accent} /> : null}
            {selectedOrder ? (
              <Card highlighted theme={theme}>
                <View style={styles.orderDetailHeader}>
                  <SectionTitle theme={theme}>{selectedOrder.order_number}</SectionTitle>
                  <Pressable accessibilityRole="button" onPress={() => setSelectedOrder(null)}>
                    <Text style={{ color: theme.textMuted, fontWeight: '600' }}>Close</Text>
                  </Pressable>
                </View>
                <BodyText muted theme={theme}>
                  {selectedOrder.total_formatted} · {selectedOrder.status}
                </BodyText>
                {selectedOrder.items.map((item, index) => (
                  <BodyText key={`${item.title}-${index}`} theme={theme}>
                    {item.quantity}× {item.title} — {item.line_total_formatted}
                  </BodyText>
                ))}
                {selectedOrder.digital_downloads.length > 0 ? (
                  <>
                    <Eyebrow theme={theme}>Downloads</Eyebrow>
                    {selectedOrder.digital_downloads.map((item) => (
                      <Pressable
                        key={item.id}
                        accessibilityRole="button"
                        onPress={() => openDownload(item.access_url)}
                        style={({ pressed }) => [{ opacity: pressed ? 0.75 : 1, marginTop: spacing.sm }]}
                      >
                        <Text style={{ color: theme.accent, fontWeight: '700' }}>{item.label}</Text>
                      </Pressable>
                    ))}
                  </>
                ) : null}
              </Card>
            ) : null}
          </>
        ) : null}
        <Pressable
          accessibilityRole="button"
          disabled={busy}
          onPress={() => void onLogout()}
          style={({ pressed }) => [
            styles.outlineBtn,
            { borderColor: theme.border, opacity: pressed ? 0.85 : 1 },
          ]}
        >
          <Text style={[styles.outlineBtnText, { color: theme.text }]}>{busy ? 'Signing out…' : 'Sign out'}</Text>
        </Pressable>
      </ScreenScroll>
    );
  }

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={{ flex: 1, backgroundColor: theme.background }}
    >
      <ScreenScroll theme={theme}>
        <PageTitle theme={theme}>{mode === 'login' ? 'Sign in' : 'Create account'}</PageTitle>
        <BodyText muted theme={theme}>
          {bootstrap.site.name} — credentials stay on this site only.
        </BodyText>

        {!authReady ? (
          <FormError
            message="Mobile sign-in is not configured on this site. Ask the admin to run database migrations (056_mobile_auth.sql), then refresh the site."
            theme={theme}
          />
        ) : null}

        {error ? <FormError message={error} theme={theme} /> : null}

        <Eyebrow theme={theme}>Email</Eyebrow>
        <TextInput
          autoCapitalize="none"
          autoCorrect={false}
          editable={!busy}
          keyboardType="email-address"
          onChangeText={setEmail}
          style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surfaceElevated }]}
          value={email}
        />

        {mode === 'register' && collectUsername ? (
          <>
            <Eyebrow theme={theme}>Username</Eyebrow>
            <TextInput
              autoCapitalize="none"
              autoCorrect={false}
              editable={!busy}
              onChangeText={setUsername}
              style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surfaceElevated }]}
              value={username}
            />
          </>
        ) : null}

        <Eyebrow theme={theme}>Password</Eyebrow>
        <TextInput
          editable={!busy}
          onChangeText={setPassword}
          secureTextEntry
          style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surfaceElevated }]}
          value={password}
        />

        {mode === 'register' ? (
          <>
            <Eyebrow theme={theme}>Confirm password</Eyebrow>
            <TextInput
              editable={!busy}
              onChangeText={setPasswordConfirm}
              secureTextEntry
              style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surfaceElevated }]}
              value={passwordConfirm}
            />
          </>
        ) : null}

        {needsTotp ? (
          <>
            <Eyebrow theme={theme}>Authenticator code</Eyebrow>
            <TextInput
              editable={!busy}
              keyboardType="number-pad"
              onChangeText={setTotpCode}
              style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surfaceElevated }]}
              value={totpCode}
            />
          </>
        ) : null}

        <PrimaryButton
          disabled={busy}
          label={mode === 'login' ? 'Sign in' : 'Register'}
          loading={busy}
          onPress={() => void (mode === 'login' ? onLogin() : onRegister())}
          theme={theme}
        />

        <Pressable
          accessibilityRole="button"
          onPress={() => {
            setMode(mode === 'login' ? 'register' : 'login');
            setError(null);
          }}
          style={({ pressed }) => [{ opacity: pressed ? 0.7 : 1 }]}
        >
          <Text style={[styles.switchMode, { color: theme.accent }]}>
            {mode === 'login' ? 'Need an account? Register' : 'Already have an account? Sign in'}
          </Text>
        </Pressable>
      </ScreenScroll>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  center: {
    alignItems: 'center',
    flex: 1,
    justifyContent: 'center',
  },
  input: {
    borderRadius: radius.md,
    borderWidth: 1,
    fontSize: 16,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
  },
  outlineBtn: {
    alignItems: 'center',
    borderRadius: radius.md,
    borderWidth: StyleSheet.hairlineWidth,
    justifyContent: 'center',
    minHeight: 50,
    paddingHorizontal: spacing.lg,
  },
  outlineBtnText: {
    fontSize: 16,
    fontWeight: '700',
  },
  switchMode: {
    fontSize: 15,
    fontWeight: '600',
    textAlign: 'center',
  },
  value: {
    fontSize: 20,
    fontWeight: '700',
  },
  badge: {
    fontSize: 12,
    fontWeight: '700',
    marginTop: spacing.sm,
  },
  orderRow: {
    gap: 2,
    marginTop: spacing.sm,
  },
  linkValue: {
    fontSize: 16,
    fontWeight: '700',
  },
  orderTitle: {
    fontSize: 16,
    fontWeight: '700',
  },
  orderDetailHeader: {
    alignItems: 'center',
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
});
