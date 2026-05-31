import type { BootstrapData } from '../types/bootstrap';

export class BootstrapError extends Error {
  constructor(
    message: string,
    public readonly code: string,
  ) {
    super(message);
    this.name = 'BootstrapError';
  }
}

type BootstrapResponse = {
  ok: boolean;
  data?: BootstrapData;
  error?: string;
  message?: string;
};

const FETCH_TIMEOUT_MS = 15000;

async function fetchJson<T>(url: string): Promise<T> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

  try {
    const response = await fetch(url, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    });

    let payload: unknown;
    try {
      payload = await response.json();
    } catch {
      throw new BootstrapError('The site did not return valid JSON.', 'invalid_json');
    }

    if (!response.ok) {
      const body = payload as BootstrapResponse;
      if (body.error === 'mobile_disabled') {
        throw new BootstrapError(
          body.message ?? 'Mobile app access is disabled on this site.',
          'mobile_disabled',
        );
      }
      throw new BootstrapError(
        body.message ?? `Request failed (${response.status}).`,
        'http_error',
      );
    }

    return payload as T;
  } catch (error) {
    if (error instanceof BootstrapError) {
      throw error;
    }
    if (error instanceof Error && error.name === 'AbortError') {
      throw new BootstrapError('The request timed out. Check the URL and try again.', 'timeout');
    }
    throw new BootstrapError('Could not reach that site. Check the URL and your connection.', 'network');
  } finally {
    clearTimeout(timer);
  }
}

export async function fetchBootstrap(siteOrigin: string): Promise<BootstrapData> {
  let bootstrapUrl = `${siteOrigin}/api/v1/mobile/bootstrap`;

  try {
    const wellKnown = await fetchJson<{ bootstrap_url?: string; struxa?: boolean }>(
      `${siteOrigin}/.well-known/struxa.json`,
    );
    if (wellKnown.bootstrap_url) {
      bootstrapUrl = wellKnown.bootstrap_url;
    }
  } catch {
    // Fall back to the default bootstrap path.
  }

  const payload = await fetchJson<BootstrapResponse>(bootstrapUrl);
  if (!payload.ok || !payload.data) {
    throw new BootstrapError(
      payload.message ?? 'This site is not a Struxa installation with mobile bootstrap enabled.',
      payload.error ?? 'not_struxa',
    );
  }

  return payload.data;
}

export const BOOTSTRAP_CACHE_TTL_MS = 5 * 60 * 1000;

export function isBootstrapStale(fetchedAt: number, now = Date.now()): boolean {
  return now - fetchedAt > BOOTSTRAP_CACHE_TTL_MS;
}
