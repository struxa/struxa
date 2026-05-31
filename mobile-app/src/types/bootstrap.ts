export type MobileTab = {
  id: string;
  label: string;
  type: string;
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
  };
  mobile: {
    welcome_title: string;
    welcome_message: string;
    tabs: MobileTab[];
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
