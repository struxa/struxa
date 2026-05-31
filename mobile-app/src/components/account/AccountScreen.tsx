import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
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
import type { SiteTheme } from '../../theme/siteTheme';

type Props = {
  siteId: string;
  bootstrap: BootstrapData;
  theme: SiteTheme;
};

type Mode = 'login' | 'register';

export function AccountScreen({ siteId, bootstrap, theme }: Props) {
  const siteOrigin = bootstrap.site.url.replace(/\/+$/, '');
  const collectUsername = bootstrap.features.auth.collect_username;

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

  const hydrate = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      let stored = await loadSiteAuth(siteId);
      if (stored && isAccessTokenExpired(stored)) {
        try {
          const refreshed = await refreshAuth(siteOrigin, stored.refreshToken);
          stored = sessionFromAuthResponse(refreshed);
          await saveSiteAuth(siteId, stored);
        } catch {
          await clearSiteAuth(siteId);
          stored = null;
        }
      }
      if (stored) {
        try {
          const user = await fetchMe(siteOrigin, stored.accessToken);
          stored = { ...stored, user };
          await saveSiteAuth(siteId, stored);
        } catch {
          await clearSiteAuth(siteId);
          stored = null;
        }
      }
      setSession(stored);
    } finally {
      setLoading(false);
    }
  }, [siteId, siteOrigin]);

  useEffect(() => {
    void hydrate();
  }, [hydrate]);

  const onLogin = async () => {
    setBusy(true);
    setError(null);
    try {
      const data = await loginUser(siteOrigin, email, password, totpCode);
      const next = sessionFromAuthResponse(data);
      await saveSiteAuth(siteId, next);
      setSession(next);
      setNeedsTotp(false);
      setTotpCode('');
    } catch (err) {
      if (err instanceof BootstrapError && err.code === 'totp_required') {
        setNeedsTotp(true);
        setError('Enter your authenticator code.');
      } else {
        setError(err instanceof Error ? err.message : 'Login failed.');
      }
    } finally {
      setBusy(false);
    }
  };

  const onRegister = async () => {
    setBusy(true);
    setError(null);
    try {
      const data = await registerUser(
        siteOrigin,
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

  const onLogout = async () => {
    if (!session) {
      return;
    }
    setBusy(true);
    try {
      await logoutUser(siteOrigin, session.refreshToken);
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
      <ScrollView
        contentContainerStyle={styles.content}
        style={{ backgroundColor: theme.background, flex: 1 }}
      >
        <Text style={[styles.title, { color: theme.text }]}>Account</Text>
        <View style={[styles.card, { backgroundColor: theme.surface, borderColor: theme.border }]}>
          <Text style={[styles.label, { color: theme.textMuted }]}>Signed in as</Text>
          <Text style={[styles.value, { color: theme.text }]}>{user.display_name ?? user.email}</Text>
          {user.username ? (
            <Text style={[styles.meta, { color: theme.textMuted }]}>@{user.username}</Text>
          ) : null}
          <Text style={[styles.meta, { color: theme.textMuted }]}>{user.email}</Text>
          {user.is_cms_staff ? (
            <Text style={[styles.badge, { color: theme.accent }]}>CMS staff on this site</Text>
          ) : null}
        </View>
        <Text style={[styles.note, { color: theme.textMuted }]}>
          Your session is stored only for {bootstrap.site.name}. Other sites you add keep separate accounts.
        </Text>
        <Pressable
          accessibilityRole="button"
          disabled={busy}
          onPress={() => void onLogout()}
          style={({ pressed }) => [
            styles.button,
            { backgroundColor: theme.surface, borderColor: theme.border, opacity: pressed ? 0.85 : 1 },
          ]}
        >
          <Text style={[styles.buttonText, { color: theme.text }]}>{busy ? 'Signing out…' : 'Sign out'}</Text>
        </Pressable>
      </ScrollView>
    );
  }

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={{ flex: 1, backgroundColor: theme.background }}
    >
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={[styles.title, { color: theme.text }]}>
          {mode === 'login' ? 'Sign in' : 'Create account'}
        </Text>
        <Text style={[styles.note, { color: theme.textMuted }]}>
          {bootstrap.site.name} — credentials stay on this site only.
        </Text>

        <Text style={[styles.fieldLabel, { color: theme.textMuted }]}>Email</Text>
        <TextInput
          autoCapitalize="none"
          autoCorrect={false}
          editable={!busy}
          keyboardType="email-address"
          onChangeText={setEmail}
          style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surface }]}
          value={email}
        />

        {mode === 'register' && collectUsername ? (
          <>
            <Text style={[styles.fieldLabel, { color: theme.textMuted }]}>Username</Text>
            <TextInput
              autoCapitalize="none"
              autoCorrect={false}
              editable={!busy}
              onChangeText={setUsername}
              style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surface }]}
              value={username}
            />
          </>
        ) : null}

        <Text style={[styles.fieldLabel, { color: theme.textMuted }]}>Password</Text>
        <TextInput
          editable={!busy}
          onChangeText={setPassword}
          secureTextEntry
          style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surface }]}
          value={password}
        />

        {mode === 'register' ? (
          <>
            <Text style={[styles.fieldLabel, { color: theme.textMuted }]}>Confirm password</Text>
            <TextInput
              editable={!busy}
              onChangeText={setPasswordConfirm}
              secureTextEntry
              style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surface }]}
              value={passwordConfirm}
            />
          </>
        ) : null}

        {needsTotp ? (
          <>
            <Text style={[styles.fieldLabel, { color: theme.textMuted }]}>Authenticator code</Text>
            <TextInput
              editable={!busy}
              keyboardType="number-pad"
              onChangeText={setTotpCode}
              style={[styles.input, { color: theme.text, borderColor: theme.border, backgroundColor: theme.surface }]}
              value={totpCode}
            />
          </>
        ) : null}

        {error ? <Text style={styles.error}>{error}</Text> : null}

        <Pressable
          accessibilityRole="button"
          disabled={busy}
          onPress={() => void (mode === 'login' ? onLogin() : onRegister())}
          style={({ pressed }) => [
            styles.button,
            { backgroundColor: theme.accent, opacity: busy ? 0.6 : pressed ? 0.85 : 1 },
          ]}
        >
          {busy ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <Text style={[styles.buttonText, { color: '#fff' }]}>
              {mode === 'login' ? 'Sign in' : 'Register'}
            </Text>
          )}
        </Pressable>

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
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  content: {
    padding: 20,
    gap: 10,
    paddingBottom: 32,
  },
  title: {
    fontSize: 28,
    fontWeight: '800',
  },
  note: {
    fontSize: 14,
    lineHeight: 20,
    marginBottom: 4,
  },
  fieldLabel: {
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  input: {
    borderWidth: 1,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
  },
  button: {
    marginTop: 8,
    borderRadius: 12,
    borderWidth: StyleSheet.hairlineWidth,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 48,
    paddingHorizontal: 16,
  },
  buttonText: {
    fontSize: 16,
    fontWeight: '700',
  },
  switchMode: {
    textAlign: 'center',
    fontSize: 15,
    fontWeight: '600',
    marginTop: 8,
  },
  error: {
    color: '#f87171',
    fontSize: 14,
    lineHeight: 20,
  },
  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 16,
    padding: 16,
    gap: 4,
  },
  label: {
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  value: {
    fontSize: 20,
    fontWeight: '700',
  },
  meta: {
    fontSize: 14,
  },
  badge: {
    marginTop: 8,
    fontSize: 12,
    fontWeight: '700',
  },
});
