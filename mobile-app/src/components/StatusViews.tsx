import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import type { SiteTheme } from '../theme/siteTheme';

type Props = {
  theme: SiteTheme;
  label?: string;
};

export function LoadingView({ theme, label = 'Loading…' }: Props) {
  return (
    <View style={[styles.wrap, { backgroundColor: theme.background }]}>
      <ActivityIndicator color={theme.accent} size="large" />
      <Text style={[styles.label, { color: theme.textMuted }]}>{label}</Text>
    </View>
  );
}

type ErrorProps = {
  theme: SiteTheme;
  message: string;
  onRetry?: () => void;
};

export function ErrorView({ theme, message, onRetry }: ErrorProps) {
  return (
    <View style={[styles.wrap, { backgroundColor: theme.background }]}>
      <Text style={[styles.errorTitle, { color: theme.text }]}>Something went wrong</Text>
      <Text style={[styles.errorBody, { color: theme.textMuted }]}>{message}</Text>
      {onRetry ? (
        <Pressable
          accessibilityRole="button"
          onPress={onRetry}
          style={({ pressed }) => [
            styles.retryBtn,
            { backgroundColor: theme.accent, opacity: pressed ? 0.85 : 1 },
          ]}
        >
          <Text style={styles.retryText}>Try again</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
    gap: 12,
  },
  label: {
    fontSize: 15,
  },
  errorTitle: {
    fontSize: 20,
    fontWeight: '700',
    textAlign: 'center',
  },
  errorBody: {
    fontSize: 15,
    lineHeight: 22,
    textAlign: 'center',
  },
  retryBtn: {
    marginTop: 8,
    borderRadius: 12,
    paddingHorizontal: 18,
    paddingVertical: 12,
  },
  retryText: {
    color: '#fff',
    fontWeight: '700',
    fontSize: 15,
  },
});
