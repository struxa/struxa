export type EntrySummary = {
  id: number;
  title: string;
  slug: string;
  status: string;
  published_at: string | null;
  updated_at: string;
  public_url: string | null;
  public_path: string | null;
  excerpt: string;
  featured_image_url: string | null;
};

export type EntryListMeta = {
  type_slug: string;
  type_name: string;
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
};

export type EntryListResponse = {
  ok: boolean;
  data: EntrySummary[];
  meta: EntryListMeta;
  error?: string;
  message?: string;
};

export type EntryField = {
  key: string;
  label: string;
  type: string;
  value: string;
  html: string;
};

export type EntryDetailPayload = {
  entry: {
    id: number;
    title: string;
    slug: string;
    status: string;
    published_at: string | null;
    seo_title: string;
    seo_description: string;
    featured_image_url: string | null;
    public_url: string | null;
  };
  fields: EntryField[];
  taxonomies: Array<{
    slug: string;
    name: string;
    terms: Array<{ id: number; slug: string; name: string }>;
  }>;
};

export type EntryDetailResponse = {
  ok: boolean;
  data: EntryDetailPayload;
  error?: string;
  message?: string;
};
