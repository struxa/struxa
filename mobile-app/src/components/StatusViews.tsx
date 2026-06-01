import { Ionicons } from '@expo/vector-icons';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { radius, spacing } from '../theme/layout';
import type { SiteTheme } from '../theme/siteTheme';

type Props = {
  theme: SiteTheme;
  label?: string;
};

export function LoadingView({ theme, label = 'Loading…' }: Props) {
  return (
    <View style={[styles.wrap, { backgroundColor: theme.background }]}>
      <View style={[styles.iconCircle, { backgroundColor: theme.accentSoft }]}>
        <ActivityIndicator color={theme.accent} size="small" />
      </View>
      <Text style={[styles.label, { color: theme.textSecondary }]}>{label}</Text>
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
      <View style={[styles.iconCircle, { backgroundColor: 'rgba(251, 113, 133, 0.12)' }]}>
        <Ionicons color={theme.danger} name="alert-circle-outline" size={28} />
      </View>
      <Text style={[styles.errorTitle, { color: theme.text }]}>Something went wrong</Text>
      <Text style={[styles.errorBody, { color: theme.textMuted }]}>{message}</Text>
      {onRetry ? (
        <Pressable
          accessibilityRole="button"
          onPress={onRetry}
          style={({ pressed }) => [
            styles.retryBtn,
            { backgroundColor: theme.accent, opacity: pressed ? 0.88 : 1 },
          ]}
        >
          <Text style={[styles.retryText, { color: theme.onAccent }]}>Try again</Text>
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
    padding: spacing.xl,
    gap: spacing.sm,
  },
  iconCircle: {
    width: 64,
    height: 64,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.xs,
  },
  label: {
    fontSize: 16,
    fontWeight: '500',
  },
  errorTitle: {
    fontSize: 22,
    fontWeight: '800',
    textAlign: 'center',
    letterSpacing: -0.3,
  },
  errorBody: {
    fontSize: 15,
    lineHeight: 22,
    textAlign: 'center',
    maxWidth: 320,
  },
  retryBtn: {
    marginTop: spacing.sm,
    borderRadius: radius.md,
    paddingHorizontal: spacing.lg,
    paddingVertical: 13,
  },
  retryText: {
    fontWeight: '700',
    fontSize: 15,
  },
});
