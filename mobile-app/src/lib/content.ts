import type { EntryDetailResponse, EntryListResponse } from '../types/content';
import { BootstrapError } from './bootstrap';

async function fetchMobileJson<T>(url: string): Promise<T> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    });
    const payload = (await response.json()) as T & { ok?: boolean; message?: string; error?: string };
    if (!response.ok || payload.ok === false) {
      throw new BootstrapError(
        payload.message ?? `Request failed (${response.status}).`,
        payload.error ?? 'http_error',
      );
    }
    return payload;
  } catch (error) {
    if (error instanceof BootstrapError) {
      throw error;
    }
    if (error instanceof Error && error.name === 'AbortError') {
      throw new BootstrapError('Request timed out.', 'timeout');
    }
    throw new BootstrapError('Could not load content.', 'network');
  } finally {
    clearTimeout(timer);
  }
}

export async function fetchEntryList(
  siteOrigin: string,
  typeSlug: string,
  page = 1,
  perPage = 20,
): Promise<EntryListResponse> {
  const url =
    `${siteOrigin}/api/v1/mobile/content/${encodeURIComponent(typeSlug)}/entries` +
    `?page=${page}&per_page=${perPage}`;

  return fetchMobileJson<EntryListResponse>(url);
}

export async function fetchEntryDetail(
  siteOrigin: string,
  typeSlug: string,
  entrySlug: string,
): Promise<EntryDetailResponse> {
  const url =
    `${siteOrigin}/api/v1/mobile/content/${encodeURIComponent(typeSlug)}/entries/` +
    encodeURIComponent(entrySlug);

  return fetchMobileJson<EntryDetailResponse>(url);
}

export function stripHtml(html: string): string {
  return html
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) {
    return '';
  }
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return iso;
  }
  return date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}
