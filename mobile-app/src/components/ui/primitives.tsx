import { Ionicons } from '@expo/vector-icons';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
  type StyleProp,
  type ViewStyle,
} from 'react-native';

import { radius, spacing } from '../../theme/layout';
import type { SiteTheme } from '../../theme/siteTheme';

type CardProps = {
  theme: SiteTheme;
  children: React.ReactNode;
  onPress?: () => void;
  highlighted?: boolean;
  style?: StyleProp<ViewStyle>;
};

export function Card({ theme, children, onPress, highlighted, style }: CardProps) {
  const inner = (
    <View
      style={[
        styles.card,
        {
          backgroundColor: theme.surfaceElevated,
          borderColor: highlighted ? theme.accent : theme.border,
          shadowColor: theme.shadow,
        },
        highlighted && { borderWidth: 1.5 },
        style,
      ]}
    >
      {children}
    </View>
  );

  if (onPress) {
    return (
      <Pressable accessibilityRole="button" onPress={onPress} style={({ pressed }) => [{ opacity: pressed ? 0.88 : 1 }]}>
        {inner}
      </Pressable>
    );
  }

  return inner;
}

export function ScreenScroll({
  theme,
  children,
  contentStyle,
}: {
  theme: SiteTheme;
  children: React.ReactNode;
  contentStyle?: StyleProp<ViewStyle>;
}) {
  return (
    <ScrollView
      contentContainerStyle={[styles.screenContent, contentStyle]}
      showsVerticalScrollIndicator={false}
      style={{ flex: 1, backgroundColor: theme.background }}
    >
      {children}
    </ScrollView>
  );
}

export function PageTitle({ theme, children }: { theme: SiteTheme; children: string }) {
  return <Text style={[styles.pageTitle, { color: theme.text }]}>{children}</Text>;
}

export function SectionTitle({ theme, children }: { theme: SiteTheme; children: string }) {
  return <Text style={[styles.sectionTitle, { color: theme.text }]}>{children}</Text>;
}

export function Eyebrow({ theme, children }: { theme: SiteTheme; children: string }) {
  return <Text style={[styles.eyebrow, { color: theme.textMuted }]}>{children}</Text>;
}

export function BodyText({ theme, children, muted }: { theme: SiteTheme; children: React.ReactNode; muted?: boolean }) {
  return <Text style={[styles.body, { color: muted ? theme.textMuted : theme.textSecondary }]}>{children}</Text>;
}

export function BackLink({ theme, label, onPress }: { theme: SiteTheme; label?: string; onPress: () => void }) {
  return (
    <Pressable
      accessibilityRole="button"
      hitSlop={8}
      onPress={onPress}
      style={({ pressed }) => [styles.backRow, { opacity: pressed ? 0.7 : 1 }]}
    >
      <Ionicons color={theme.accent} name="chevron-back" size={20} />
      <Text style={[styles.backText, { color: theme.accent }]}>{label ?? 'Back'}</Text>
    </Pressable>
  );
}

export function FormError({ theme, message }: { theme: SiteTheme; message: string }) {
  return (
    <View style={[styles.formError, { backgroundColor: 'rgba(251, 113, 133, 0.12)', borderColor: theme.danger }]}>
      <Ionicons color={theme.danger} name="alert-circle" size={18} />
      <Text style={[styles.formErrorText, { color: theme.danger }]}>{message}</Text>
    </View>
  );
}

export function PrimaryButton({
  theme,
  label,
  onPress,
  disabled,
  loading,
}: {
  theme: SiteTheme;
  label: string;
  onPress: () => void;
  disabled?: boolean;
  loading?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled || loading}
      onPress={onPress}
      style={({ pressed }) => [
        styles.primaryBtn,
        {
          backgroundColor: theme.accent,
          opacity: disabled ? 0.45 : pressed ? 0.88 : 1,
        },
      ]}
    >
      {loading ? (
        <ActivityIndicator color={theme.onAccent} size="small" />
      ) : (
        <Text style={[styles.primaryBtnText, { color: theme.onAccent }]}>{label}</Text>
      )}
    </Pressable>
  );
}

export function IconButton({
  theme,
  icon,
  label,
  onPress,
  accent,
  disabled,
}: {
  theme: SiteTheme;
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
  accent?: boolean;
  disabled?: boolean;
}) {
  return (
    <Pressable
      accessibilityLabel={label}
      accessibilityRole="button"
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [
        styles.iconBtn,
        {
          backgroundColor: accent ? theme.accentSoft : theme.surfaceOverlay,
          opacity: disabled ? 0.45 : pressed ? 0.75 : 1,
        },
      ]}
    >
      <Ionicons color={accent ? theme.accent : theme.textMuted} name={icon} size={20} />
    </Pressable>
  );
}

export function HeroCard({ theme, title, message }: { theme: SiteTheme; title: string; message?: string }) {
  return (
    <View style={[styles.hero, { backgroundColor: theme.accentSoft, borderColor: theme.border }]}>
      <View style={[styles.heroAccent, { backgroundColor: theme.accent }]} />
      <Text style={[styles.heroTitle, { color: theme.text }]}>{title}</Text>
      {message ? <Text style={[styles.heroMessage, { color: theme.textSecondary }]}>{message}</Text> : null}
    </View>
  );
}

export function FeatureChip({ theme, label, value }: { theme: SiteTheme; label: string; value: string }) {
  return (
    <View style={[styles.chip, { backgroundColor: theme.surfaceOverlay, borderColor: theme.border }]}>
      <Text style={[styles.chipLabel, { color: theme.textMuted }]}>{label}</Text>
      <Text style={[styles.chipValue, { color: theme.text }]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.xs,
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.18,
    shadowRadius: 12,
    elevation: 3,
  },
  screenContent: {
    padding: spacing.lg,
    paddingBottom: spacing.xl,
    gap: spacing.md,
  },
  pageTitle: {
    fontSize: 30,
    fontWeight: '800',
    letterSpacing: -0.5,
  },
  sectionTitle: {
    fontSize: 18,
    fontWeight: '700',
    letterSpacing: -0.2,
  },
  eyebrow: {
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.8,
    textTransform: 'uppercase',
  },
  body: {
    fontSize: 16,
    lineHeight: 24,
  },
  backRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 2,
    paddingVertical: spacing.xs,
  },
  backText: {
    fontSize: 16,
    fontWeight: '600',
  },
  primaryBtn: {
    borderRadius: radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 50,
    paddingHorizontal: spacing.lg,
  },
  primaryBtnText: {
    fontSize: 16,
    fontWeight: '700',
  },
  iconBtn: {
    width: 38,
    height: 38,
    borderRadius: radius.sm,
    alignItems: 'center',
    justifyContent: 'center',
  },
  hero: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.xl,
    padding: spacing.lg,
    gap: spacing.sm,
    overflow: 'hidden',
  },
  heroAccent: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 3,
  },
  heroTitle: {
    fontSize: 26,
    fontWeight: '800',
    letterSpacing: -0.4,
  },
  heroMessage: {
    fontSize: 16,
    lineHeight: 24,
  },
  chip: {
    flex: 1,
    minWidth: '46%',
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.md,
    gap: 4,
  },
  chipLabel: {
    fontSize: 11,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  chipValue: {
    fontSize: 15,
    fontWeight: '600',
  },
  formError: {
    alignItems: 'flex-start',
    borderRadius: radius.md,
    borderWidth: 1,
    flexDirection: 'row',
    gap: spacing.sm,
    minHeight: 48,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    width: '100%',
  },
  formErrorText: {
    flex: 1,
    fontSize: 14,
    lineHeight: 20,
  },
});
