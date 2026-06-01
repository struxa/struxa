import type {
  AuthApiResponse,
  AuthTokenResponse,
  MobileUser,
  RegisterResponse,
} from '../types/auth';
import { BootstrapError } from './bootstrap';

type ApiErrorBody = {
  ok?: boolean;
  error?: string;
  message?: string;
  code?: number;
};

function apiFailureMessage(payload: ApiErrorBody, status: number): string {
  if (typeof payload.message === 'string' && payload.message.trim() !== '') {
    return payload.message;
  }
  if (typeof payload.error === 'string' && payload.error.trim() !== '') {
    return payload.error;
  }

  return `Request failed (${status}).`;
}

async function parseMobileJson(response: Response): Promise<ApiErrorBody & { data?: unknown }> {
  const text = await response.text();
  if (text.trim() === '') {
    throw new BootstrapError(
      response.ok ? 'The site returned an empty response.' : `Request failed (${response.status}).`,
      'empty_response',
    );
  }

  try {
    return JSON.parse(text) as ApiErrorBody & { data?: unknown };
  } catch {
    if (/Slim Application Error|Slim\\\\|SQLSTATE|cms_mobile_refresh_tokens/i.test(text)) {
      throw new BootstrapError(
        'Mobile sign-in is not set up on this site yet. Ask the admin to run database migrations (056_mobile_auth.sql).',
        'database_error',
      );
    }

    throw new BootstrapError(
      `The site returned an unexpected response (${response.status}).`,
      'invalid_json',
    );
  }
}

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

    const payload = await parseMobileJson(response);

    if (!response.ok || payload.ok === false) {
      throw new BootstrapError(apiFailureMessage(payload, response.status), payload.error ?? 'http_error');
    }

    if (payload.data === undefined || payload.data === null) {
      throw new BootstrapError('The site returned an incomplete auth response.', 'invalid_response');
    }

    return payload.data as T;
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
  loginUrl: string,
  email: string,
  password: string,
  totpCode = '',
): Promise<AuthTokenResponse> {
  return postAuth<AuthTokenResponse>(loginUrl, {
    email,
    password,
    totp_code: totpCode,
  });
}

export async function registerUser(
  registerUrl: string,
  email: string,
  password: string,
  passwordConfirm: string,
  username = '',
): Promise<RegisterResponse> {
  return postAuth<RegisterResponse>(registerUrl, {
    email,
    password,
    password_confirm: passwordConfirm,
    username,
  });
}

export async function refreshAuth(
  refreshUrl: string,
  refreshToken: string,
): Promise<AuthTokenResponse> {
  return postAuth<AuthTokenResponse>(refreshUrl, {
    refresh_token: refreshToken,
  });
}

export async function logoutUser(logoutUrl: string, refreshToken: string): Promise<void> {
  await postAuth<Record<string, never>>(logoutUrl, {
    refresh_token: refreshToken,
  });
}

export async function fetchMe(meUrl: string, accessToken: string): Promise<MobileUser> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(meUrl, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    });
    const payload = (await parseMobileJson(response)) as AuthApiResponse<{ user: MobileUser }>;
    if (!response.ok || payload.ok === false) {
      throw new BootstrapError(apiFailureMessage(payload, response.status), payload.error ?? 'http_error');
    }
    if (!payload.data?.user) {
      throw new BootstrapError('The site returned an incomplete profile response.', 'invalid_response');
    }
    return payload.data.user;
  } catch (error) {
    if (error instanceof BootstrapError) {
      throw error;
    }
    if (error instanceof Error && error.name === 'AbortError') {
      throw new BootstrapError('Request timed out.', 'timeout');
    }
    throw new BootstrapError('Could not load profile.', 'network');
  } finally {
    clearTimeout(timer);
  }
}
