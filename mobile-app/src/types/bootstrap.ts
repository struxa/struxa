export type MobileTab = {
  id: string;
  label: string;
  type: string;
  content_type_slug?: string;
  plugin_slug?: string;
  screen?: string;
  url?: string;
};

export type BootstrapNavigationItem = {
  label: string;
  href: string;
  target: string;
};

export type ContentTypeSummary = {
  slug: string;
  name: string;
  description: string;
  route: string;
  supports_featured_image: boolean;
};

export type BootstrapData = {
  schema_version: number;
  cms_version: string;
  site: {
    name: string;
    tagline: string;
    url: string;
    language: string;
  };
  branding: {
    logo_url: string;
    favicon_url: string;
    accent_color: string;
    theme_slug: string;
  };
  features: {
    commerce: boolean;
    search: boolean;
    comments: boolean;
    mobile_auth: boolean;
    mobile_auth_ready: boolean;
    browse: boolean;
    auth: {
      login_path: string;
      register_path: string;
      google_sso: boolean;
      collect_username: boolean;
    };
  };
  api: {
    rest_base: string;
    graphql: string;
    bootstrap: string;
    content_base: string;
    auth_login: string;
    auth_register: string;
    auth_refresh: string;
    auth_logout: string;
    auth_me: string;
    commerce_products?: string;
    commerce_checkout?: string;
    commerce_orders?: string;
    commerce_downloads?: string;
  };
  mobile: {
    welcome_title: string;
    welcome_message: string;
    tabs: MobileTab[];
    add_site_deeplink?: string;
    add_site_web_url?: string;
  };
  navigation: {
    header: BootstrapNavigationItem[];
    footer?: BootstrapNavigationItem[];
  };
  content_types: ContentTypeSummary[];
  commerce?: {
    currency: string;
    shop_title: string;
    shop_path: string;
    product_type_slug?: string;
    needs_checkout_country?: boolean;
  };
};

export type RegisteredSite = {
  id: string;
  url: string;
  label: string;
  addedAt: string;
};

export type CachedBootstrap = {
  data: BootstrapData;
  fetchedAt: number;
};
