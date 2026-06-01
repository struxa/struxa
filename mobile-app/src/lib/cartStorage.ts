import AsyncStorage from '@react-native-async-storage/async-storage';

import type { StoredCartLine } from '../types/commerce';

const prefix = 'struxa_cart_';

function cartKey(siteId: string): string {
  return `${prefix}${siteId}`;
}

export async function loadSiteCart(siteId: string): Promise<StoredCartLine[]> {
  const raw = await AsyncStorage.getItem(cartKey(siteId));
  if (!raw) {
    return [];
  }
  try {
    const parsed = JSON.parse(raw) as StoredCartLine[];
    if (!Array.isArray(parsed)) {
      return [];
    }
    return parsed.filter(
      (line) =>
        typeof line.entryId === 'number' &&
        line.entryId > 0 &&
        typeof line.quantity === 'number' &&
        line.quantity > 0,
    );
  } catch {
    return [];
  }
}

export async function saveSiteCart(siteId: string, lines: StoredCartLine[]): Promise<void> {
  if (lines.length === 0) {
    await AsyncStorage.removeItem(cartKey(siteId));
    return;
  }
  await AsyncStorage.setItem(cartKey(siteId), JSON.stringify(lines));
}

export async function clearSiteCart(siteId: string): Promise<void> {
  await AsyncStorage.removeItem(cartKey(siteId));
}

export function cartCount(lines: StoredCartLine[]): number {
  return lines.reduce((sum, line) => sum + line.quantity, 0);
}

export function toCartLineInput(lines: StoredCartLine[]): Array<{ entry_id: number; quantity: number }> {
  return lines.map((line) => ({ entry_id: line.entryId, quantity: line.quantity }));
}

export function upsertCartLine(lines: StoredCartLine[], entryId: number, quantity: number): StoredCartLine[] {
  const next = lines.filter((line) => line.entryId !== entryId);
  if (quantity > 0) {
    next.push({ entryId, quantity: Math.min(99, quantity) });
  }
  return next;
}
