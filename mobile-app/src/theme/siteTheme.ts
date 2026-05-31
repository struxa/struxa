export type SiteTheme = {
  accent: string;
  accentSoft: string;
  background: string;
  surface: string;
  border: string;
  text: string;
  textMuted: string;
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

export function buildSiteTheme(accentColor?: string): SiteTheme {
  const accent = hexToRgb(accentColor ?? '') ? accentColor! : FALLBACK_ACCENT;

  return {
    accent,
    accentSoft: mix(accent, { r: 11, g: 18, b: 32 }, 0.72),
    background: '#0b1220',
    surface: '#121a2e',
    border: '#243049',
    text: '#eef2ff',
    textMuted: '#94a3b8',
  };
}
