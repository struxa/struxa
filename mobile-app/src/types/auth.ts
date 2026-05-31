export type MobileUser = {
  id: number;
  email: string;
  username: string | null;
  display_name: string | null;
  is_active: boolean;
  is_cms_staff: boolean;
};

export type AuthTokenResponse = {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  expires_at: number;
  token_type: string;
  user: MobileUser;
};

export type RegisterResponse =
  | ({ activated: true } & AuthTokenResponse)
  | {
      activated: false;
      message: string;
      user: MobileUser;
    };

export type SiteAuthSession = {
  accessToken: string;
  refreshToken: string;
  expiresAt: number;
  user: MobileUser;
};

export type AuthApiResponse<T> = {
  ok: boolean;
  data: T;
  error?: string;
  message?: string;
};
