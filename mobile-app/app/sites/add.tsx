import { useState } from 'react';
import { useRouter } from 'expo-router';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useSites } from '../../src/context/SitesContext';
import { buildSiteTheme } from '../../src/theme/siteTheme';

export default function AddSiteScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const theme = buildSiteTheme();
  const { addSite, loadingSiteId, error, clearError } = useSites();
  const [url, setUrl] = useState('');
  const [localError, setLocalError] = useState<string | null>(null);
  const busy = loadingSiteId !== null;

  const onSubmit = async () => {
    setLocalError(null);
    clearError();
    try {
      await addSite(url);
      router.replace('/');
    } catch (err) {
      setLocalError(err instanceof Error ? err.message : 'Could not add site.');
    }
  };

  const message = localError ?? error;

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={[styles.root, { backgroundColor: theme.background, paddingTop: insets.top + 16 }]}
    >
      <View style={styles.inner}>
        <Text style={[styles.title, { color: theme.text }]}>Add a Struxa site</Text>
        <Text style={[styles.subtitle, { color: theme.textMuted }]}>
          Enter the public URL of your Struxa site. The app loads branding and navigation from the bootstrap API.
        </Text>

        <Text style={[styles.label, { color: theme.textMuted }]}>Site URL</Text>
        <TextInput
          autoCapitalize="none"
          autoCorrect={false}
          editable={!busy}
          keyboardType="url"
          onChangeText={setUrl}
          placeholder="https://yoursite.com"
          placeholderTextColor={theme.textMuted}
          returnKeyType="go"
          onSubmitEditing={() => void onSubmit()}
          style={[
            styles.input,
            {
              color: theme.text,
              borderColor: theme.border,
              backgroundColor: theme.surface,
            },
          ]}
          value={url}
        />

        {message ? <Text style={[styles.error, { color: '#f87171' }]}>{message}</Text> : null}

        <Pressable
          accessibilityRole="button"
          disabled={busy || url.trim() === ''}
          onPress={() => void onSubmit()}
          style={({ pressed }) => [
            styles.submit,
            {
              backgroundColor: theme.accent,
              opacity: busy || url.trim() === '' ? 0.5 : pressed ? 0.85 : 1,
            },
          ]}
        >
          {busy ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <Text style={styles.submitText}>Connect</Text>
          )}
        </Pressable>

        <Pressable
          accessibilityRole="button"
          onPress={() => router.back()}
          style={({ pressed }) => [{ opacity: pressed ? 0.7 : 1, marginTop: 12 }]}
        >
          <Text style={[styles.cancel, { color: theme.textMuted }]}>Cancel</Text>
        </Pressable>

        <Text style={[styles.hint, { color: theme.textMuted }]}>
          Local dev tip: use your machine IP (not localhost) on a physical device, or{' '}
          <Text style={{ color: theme.accent }}>http://10.0.2.2:3439</Text> on Android emulator.
        </Text>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
  },
  inner: {
    padding: 20,
    gap: 10,
  },
  title: {
    fontSize: 28,
    fontWeight: '800',
  },
  subtitle: {
    fontSize: 15,
    lineHeight: 22,
    marginBottom: 8,
  },
  label: {
    fontSize: 13,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  input: {
    borderWidth: 1,
    borderRadius: 14,
    paddingHorizontal: 14,
    paddingVertical: 14,
    fontSize: 16,
  },
  error: {
    fontSize: 14,
    lineHeight: 20,
  },
  submit: {
    marginTop: 8,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 48,
  },
  submitText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '700',
  },
  cancel: {
    textAlign: 'center',
    fontSize: 15,
  },
  hint: {
    marginTop: 16,
    fontSize: 13,
    lineHeight: 20,
  },
});
