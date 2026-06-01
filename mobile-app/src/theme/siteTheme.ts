export type SiteTheme = {
  accent: string;
  accentSoft: string;
  onAccent: string;
  background: string;
  surface: string;
  surfaceElevated: string;
  surfaceOverlay: string;
  border: string;
  text: string;
  textSecondary: string;
  textMuted: string;
  shadow: string;
  danger: string;
};

const FALLBACK_ACCENT = '#8b7cf6';

function clampChannel(value: number): number {
  return Math.max(0, Math.min(255, value));
}

function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
  const match = /^#?([0-9a-fA-F]{6})$/.exec(hex.trim());
  if (!match) {
    return null;
  }
  const int = parseInt(match[1], 16);
  return {
    r: (int >> 16) & 255,
    g: (int >> 8) & 255,
    b: int & 255,
  };
}

function mix(hex: string, target: { r: number; g: number; b: number }, amount: number): string {
  const rgb = hexToRgb(hex);
  if (!rgb) {
    return hex;
  }
  const r = clampChannel(Math.round(rgb.r + (target.r - rgb.r) * amount));
  const g = clampChannel(Math.round(rgb.g + (target.g - rgb.g) * amount));
  const b = clampChannel(Math.round(rgb.b + (target.b - rgb.b) * amount));
  return `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`;
}

function luminance(hex: string): number {
  const rgb = hexToRgb(hex);
  if (!rgb) {
    return 0;
  }
  return (0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b) / 255;
}

export function buildSiteTheme(accentColor?: string): SiteTheme {
  const accent = hexToRgb(accentColor ?? '') ? accentColor! : FALLBACK_ACCENT;
  const darkBase = { r: 11, g: 18, b: 32 };
  const midBase = { r: 18, g: 26, b: 46 };

  return {
    accent,
    accentSoft: mix(accent, darkBase, 0.78),
    onAccent: luminance(accent) > 0.62 ? '#0b1220' : '#ffffff',
    background: '#080e1a',
    surface: '#0f1628',
    surfaceElevated: '#151e34',
    surfaceOverlay: 'rgba(255, 255, 255, 0.05)',
    border: mix('#ffffff', darkBase, 0.88),
    text: '#f1f5ff',
    textSecondary: '#c7d2e8',
    textMuted: '#8b98b3',
    shadow: mix(accent, midBase, 0.65),
    danger: '#fb7185',
  };
}
