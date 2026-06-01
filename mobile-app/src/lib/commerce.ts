import type {
  CartLineInput,
  CartQuoteResponse,
  CheckoutResponse,
  DownloadListResponse,
  OrderDetailResponse,
  OrderListResponse,
  ProductDetailResponse,
  ProductListResponse,
} from '../types/commerce';
import { BootstrapError } from './bootstrap';

async function fetchMobileJson<T>(
  url: string,
  options: RequestInit = {},
): Promise<T> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 20000);

  try {
    const response = await fetch(url, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.headers ?? {}),
      },
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
    throw new BootstrapError('Could not reach the shop.', 'network');
  } finally {
    clearTimeout(timer);
  }
}

export async function fetchProductList(
  siteOrigin: string,
  page = 1,
  perPage = 20,
): Promise<ProductListResponse> {
  const url =
    `${siteOrigin}/api/v1/mobile/commerce/products` + `?page=${page}&per_page=${perPage}`;

  return fetchMobileJson<ProductListResponse>(url);
}

export async function fetchProductDetail(
  siteOrigin: string,
  entrySlug: string,
): Promise<ProductDetailResponse> {
  const url = `${siteOrigin}/api/v1/mobile/commerce/products/${encodeURIComponent(entrySlug)}`;
  return fetchMobileJson<ProductDetailResponse>(url);
}

export async function quoteCart(
  siteOrigin: string,
  lines: CartLineInput[],
  shipCountry?: string | null,
  couponCode?: string | null,
): Promise<CartQuoteResponse> {
  const url = `${siteOrigin}/api/v1/mobile/commerce/cart/quote`;
  return fetchMobileJson<CartQuoteResponse>(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      lines,
      ship_country: shipCountry ?? undefined,
      coupon_code: couponCode ?? undefined,
    }),
  });
}

export async function startCheckout(
  siteOrigin: string,
  lines: CartLineInput[],
  shipCountry?: string | null,
  couponCode?: string | null,
  accessToken?: string | null,
): Promise<CheckoutResponse> {
  const url = `${siteOrigin}/api/v1/mobile/commerce/checkout`;
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  if (accessToken) {
    headers.Authorization = `Bearer ${accessToken}`;
  }
  return fetchMobileJson<CheckoutResponse>(url, {
    method: 'POST',
    headers,
    body: JSON.stringify({
      lines,
      ship_country: shipCountry ?? undefined,
      coupon_code: couponCode ?? undefined,
    }),
  });
}

export async function fetchOrders(
  siteOrigin: string,
  accessToken: string,
): Promise<OrderListResponse> {
  const url = `${siteOrigin}/api/v1/mobile/commerce/orders`;
  return fetchMobileJson<OrderListResponse>(url, {
    headers: { Authorization: `Bearer ${accessToken}` },
  });
}

export async function fetchOrderDetail(
  siteOrigin: string,
  accessToken: string,
  orderNumber: string,
): Promise<OrderDetailResponse> {
  const url = `${siteOrigin}/api/v1/mobile/commerce/orders/${encodeURIComponent(orderNumber)}`;
  return fetchMobileJson<OrderDetailResponse>(url, {
    headers: { Authorization: `Bearer ${accessToken}` },
  });
}

export async function fetchDigitalDownloads(
  siteOrigin: string,
  accessToken: string,
): Promise<DownloadListResponse> {
  const url = `${siteOrigin}/api/v1/mobile/commerce/downloads`;
  return fetchMobileJson<DownloadListResponse>(url, {
    headers: { Authorization: `Bearer ${accessToken}` },
  });
}
