import type {
  AuthApiResponse,
  AuthTokenResponse,
  MobileUser,
  RegisterResponse,
} from '../types/auth';
import { BootstrapError } from './bootstrap';

async function postAuth<T>(url: string, body: Record<string, string>): Promise<T> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body),
      signal: controller.signal,
    });

    const payload = (await response.json()) as AuthApiResponse<T> & {
      error?: string;
      message?: string;
    };

    if (!response.ok || payload.ok === false) {
      throw new BootstrapError(
        payload.message ?? `Request failed (${response.status}).`,
        payload.error ?? 'http_error',
      );
    }

    return payload.data;
  } catch (error) {
    if (error instanceof BootstrapError) {
      throw error;
    }
    if (error instanceof Error && error.name === 'AbortError') {
      throw new BootstrapError('Request timed out.', 'timeout');
    }
    throw new BootstrapError('Could not reach the site.', 'network');
  } finally {
    clearTimeout(timer);
  }
}

export async function loginUser(
  siteOrigin: string,
  email: string,
  password: string,
  totpCode = '',
): Promise<AuthTokenResponse> {
  return postAuth<AuthTokenResponse>(`${siteOrigin}/api/v1/mobile/auth/login`, {
    email,
    password,
    totp_code: totpCode,
  });
}

export async function registerUser(
  siteOrigin: string,
  email: string,
  password: string,
  passwordConfirm: string,
  username = '',
): Promise<RegisterResponse> {
  return postAuth<RegisterResponse>(`${siteOrigin}/api/v1/mobile/auth/register`, {
    email,
    password,
    password_confirm: passwordConfirm,
    username,
  });
}

export async function refreshAuth(
  siteOrigin: string,
  refreshToken: string,
): Promise<AuthTokenResponse> {
  return postAuth<AuthTokenResponse>(`${siteOrigin}/api/v1/mobile/auth/refresh`, {
    refresh_token: refreshToken,
  });
}

export async function logoutUser(siteOrigin: string, refreshToken: string): Promise<void> {
  await postAuth<Record<string, never>>(`${siteOrigin}/api/v1/mobile/auth/logout`, {
    refresh_token: refreshToken,
  });
}

export async function fetchMe(siteOrigin: string, accessToken: string): Promise<MobileUser> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(`${siteOrigin}/api/v1/mobile/auth/me`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    });
    const payload = (await response.json()) as AuthApiResponse<{ user: MobileUser }>;
    if (!response.ok || payload.ok === false) {
      throw new BootstrapError(
        payload.message ?? `Request failed (${response.status}).`,
        payload.error ?? 'http_error',
      );
    }
    return payload.data.user;
  } catch (error) {
    if (error instanceof BootstrapError) {
      throw error;
    }
    throw new BootstrapError('Could not load profile.', 'network');
  } finally {
    clearTimeout(timer);
  }
}
