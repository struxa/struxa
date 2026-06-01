export type ProductSummary = {
  id: number;
  slug: string;
  title: string;
  excerpt: string;
  featured_image_url: string | null;
  price_cents: number;
  price_formatted: string;
  currency: string;
  in_stock: boolean;
};

export type ProductDetail = ProductSummary & {
  sku: string | null;
  stock_qty: number | null;
  uses_stripe_price: boolean;
  product_type_slug: string;
  url: string;
};

export type ProductListResponse = {
  ok: true;
  data: ProductSummary[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
    product_type_slug: string;
  };
};

export type ProductDetailResponse = {
  ok: true;
  data: ProductDetail;
};

export type CartLineInput = {
  entry_id: number;
  quantity: number;
};

export type CartLineQuote = {
  entry_id: number;
  slug: string;
  title: string;
  quantity: number;
  unit_price_cents: number;
  line_total_cents: number;
  price_formatted: string;
  featured_image_url: string | null;
  in_stock: boolean;
};

export type CartTotals = {
  subtotal_cents: number;
  discount_cents: number;
  tax_cents: number;
  shipping_cents: number;
  total_cents: number;
  total_formatted: string;
  coupon_code: string | null;
  shipping_label: string | null;
};

export type CartQuote = {
  lines: CartLineQuote[];
  subtotal_cents: number;
  currency: string;
  totals: CartTotals;
  coupon_code: string | null;
  coupon_error: string | null;
  ship_country: string | null;
};

export type CartQuoteResponse = {
  ok: true;
  data: CartQuote;
};

export type CheckoutResponse = {
  ok: true;
  data: {
    checkout_url: string;
    order_number: string;
    order_id: number;
  };
};

export type OrderSummary = {
  order_number: string;
  status: string;
  currency: string;
  total_cents: number;
  total_formatted: string;
  item_count: number;
  download_count?: number;
  created_at: string;
  paid_at: string | null;
};

export type DigitalDownload = {
  id: number;
  order_number: string;
  label: string;
  delivery_type: 'file' | 'url' | 'entry' | string;
  access_url: string;
  content_entry_id: number;
  content_type_slug?: string;
  entry_slug?: string;
};

export type DownloadListResponse = {
  ok: true;
  data: DigitalDownload[];
};

export type OrderListResponse = {
  ok: true;
  data: OrderSummary[];
};

export type OrderDetail = OrderSummary & {
  subtotal_cents: number;
  discount_cents: number;
  tax_cents: number;
  shipping_cents: number;
  coupon_code: string | null;
  shipping_label: string | null;
  items: Array<{
    title: string;
    quantity: number;
    unit_price_cents: number;
    line_total_cents: number;
    unit_price_formatted: string;
    line_total_formatted: string;
  }>;
  digital_downloads: DigitalDownload[];
};

export type OrderDetailResponse = {
  ok: true;
  data: OrderDetail;
};

export type StoredCartLine = {
  entryId: number;
  quantity: number;
};
