export function normalizeSiteUrl(input: string): string {
  const trimmed = input.trim();
  if (trimmed === '') {
    throw new Error('Enter a site URL.');
  }

  let candidate = trimmed;
  if (!/^https?:\/\//i.test(candidate)) {
    candidate = `https://${candidate}`;
  }

  let parsed: URL;
  try {
    parsed = new URL(candidate);
  } catch {
    throw new Error('That does not look like a valid URL.');
  }

  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    throw new Error('Use an http or https URL.');
  }

  return parsed.origin;
}

export function siteIdFromUrl(url: string): string {
  const host = url.replace(/^https?:\/\//i, '').replace(/\/+$/, '');
  const slug = host
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  return slug !== '' ? slug : 'site';
}

export function formatSiteLabel(url: string): string {
  try {
    return new URL(url).host;
  } catch {
    return url;
  }
}
