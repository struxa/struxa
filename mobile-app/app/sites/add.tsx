import { useState } from 'react';
import { useRouter } from 'expo-router';
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BodyText, PageTitle, PrimaryButton } from '../../src/components/ui/primitives';
import { useSites } from '../../src/context/SitesContext';
import { radius, spacing } from '../../src/theme/layout';
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
      style={[styles.root, { backgroundColor: theme.background, paddingTop: insets.top + spacing.md }]}
    >
      <View style={styles.inner}>
        <PageTitle theme={theme}>Add a Struxa site</PageTitle>
        <BodyText muted theme={theme}>
          Enter the public URL of your Struxa site. The app loads branding and navigation from the bootstrap API.
        </BodyText>

        <Text style={[styles.label, { color: theme.textMuted }]}>Site URL</Text>
        <TextInput
          autoCapitalize="none"
          autoCorrect={false}
          editable={!busy}
          keyboardType="url"
          onChangeText={setUrl}
          onSubmitEditing={() => void onSubmit()}
          placeholder="https://yoursite.com"
          placeholderTextColor={theme.textMuted}
          returnKeyType="go"
          style={[
            styles.input,
            {
              color: theme.text,
              borderColor: theme.border,
              backgroundColor: theme.surfaceElevated,
            },
          ]}
          value={url}
        />

        {message ? <Text style={[styles.error, { color: theme.danger }]}>{message}</Text> : null}

        <PrimaryButton
          disabled={busy || url.trim() === ''}
          label={busy ? 'Connecting…' : 'Connect'}
          loading={busy}
          onPress={() => void onSubmit()}
          theme={theme}
        />

        <Pressable
          accessibilityRole="button"
          onPress={() => router.back()}
          style={({ pressed }) => [{ opacity: pressed ? 0.7 : 1, marginTop: spacing.sm }]}
        >
          <Text style={[styles.cancel, { color: theme.textMuted }]}>Cancel</Text>
        </Pressable>

        <BodyText muted theme={theme}>
          Scan the QR code from Admin → Mobile app, or enter your site URL manually. Local dev: use your machine IP on a
          physical device, or http://10.0.2.2:3439 on Android emulator.
        </BodyText>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
  },
  inner: {
    gap: spacing.sm,
    padding: spacing.lg,
  },
  label: {
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.6,
    marginTop: spacing.sm,
    textTransform: 'uppercase',
  },
  input: {
    borderRadius: radius.md,
    borderWidth: 1,
    fontSize: 16,
    paddingHorizontal: spacing.md,
    paddingVertical: 14,
  },
  error: {
    fontSize: 14,
    lineHeight: 20,
  },
  cancel: {
    fontSize: 15,
    textAlign: 'center',
  },
});
