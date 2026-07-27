import { apiBaseUrl } from "@/lib/config/runtime";
import { signedPreviewApiPath } from "@/lib/articles/signedPreviewPath";

export type ApiStatus = {
  data: {
    name: string;
    version: string;
    mobile_ready: boolean;
    ai_author_generation_enabled: boolean;
  };
};

export type MediaResponsiveUrls = {
  original?: string | null;
  large?: string | null;
  medium?: string | null;
  thumbnail?: string | null;
  [key: string]: string | null | undefined;
};

export type MediaAsset = {
  id: number;
  url: string | null;
  path: string;
  mime_type?: string | null;
  alt_text: string | null;
  caption: string | null;
  credit?: string | null;
  source_url?: string | null;
  focal_point?: {
    x: number;
    y: number;
  } | null;
  width: number | null;
  height: number | null;
  responsive_urls?: MediaResponsiveUrls | null;
};

export type AdCreativeUpload = {
  media_id?: number | null;
  upload_token: string | null;
  url: string | null;
  thumbnail_url: string | null;
  original_name: string;
  mime_type: string | null;
  width: number | null;
  height: number | null;
  size_bytes: number | null;
};

export type AuthorListItem = {
  id: number;
  name: string;
  slug: string;
  job_title: string | null;
  short_bio: string | null;
  bio_preview: string | null;
  avatar: MediaAsset | null;
  article_count: number;
  editorial_focus: Array<{
    id: number;
    name: string;
    slug: string;
    url: string;
  }>;
  latest_article: {
    title: string;
    slug: string;
    url: string;
    published_at: string | null;
  } | null;
  url: string;
  updated_at?: string | null;
};

export type AuthorDetail = AuthorListItem & {
  full_bio: string | null;
  email: string | null;
  social_links: {
    website: string | null;
    facebook: string | null;
    instagram: string | null;
    linkedin: string | null;
    x: string | null;
  };
  seo: {
    title: string | null;
    meta_description: string | null;
    canonical_url: string | null;
    og_title: string | null;
    og_description: string | null;
    og_image: MediaAsset | null;
  };
};

export type AuthorDirectoryResponse = PaginatedResponse<AuthorListItem> & {
  directory_stats?: {
    author_count?: number | null;
    published_article_count?: number | null;
    active_contributor_count?: number | null;
  };
};

export type TeamRoleItem = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  group_key: string | null;
  sort_order: number;
  status: string;
};

export type TeamMemberAuthorLink = {
  id: number;
  name: string;
  slug: string;
  job_title: string | null;
  url: string;
};

export type TeamMemberOrgLink = {
  id: number;
  name: string;
  slug: string;
  url: string;
};

export type TeamMemberItem = {
  id: number;
  name: string;
  slug: string;
  display_title: string | null;
  short_bio: string | null;
  full_bio: string | null;
  profile_image: MediaAsset | null;
  email: string | null;
  member_type: "human" | "ai";
  is_ai: boolean;
  status: string;
  sort_order: number;
  joined_at: string | null;
  social_links: Record<string, string | null>;
  metadata: Record<string, unknown>;
  roles: TeamRoleItem[];
  primary_department: TeamMemberOrgLink | null;
  manager: TeamMemberOrgLink | null;
  author: TeamMemberAuthorLink | null;
  url: string;
  updated_at?: string | null;
};

export type TeamDepartmentSection = {
  key: string;
  title: string;
  sort_order: number;
  manager: TeamMemberItem | null;
  members: TeamMemberItem[];
};

export type TeamSection = {
  key: string;
  title: string;
  sort_order: number;
  roles: TeamRoleItem[];
  members: TeamMemberItem[];
};

export type TeamMembersResponse = {
  data: {
    sections: TeamSection[];
    department_sections: TeamDepartmentSection[];
  };
};

export type TeamDepartmentItem = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  group_key: string | null;
  status: string;
  sort_order: number;
  parent_department_id: number | null;
  manager: TeamMemberItem | null;
  members: TeamMemberItem[];
  child_departments: TeamDepartmentItem[];
  metadata: Record<string, unknown>;
  url: string;
  updated_at?: string | null;
};

export type TeamOrgChartResponse = {
  data: {
    departments: TeamDepartmentItem[];
  };
};

export type AuthorArticle = {
  id: number;
  title: string;
  canonical_title?: string;
  slug: string;
  excerpt: string | null;
  url: string;
  type?: string;
  is_sponsored?: boolean;
  sponsored_label?: string | null;
  featured_image: MediaAsset | null;
  category: {
    id?: number | null;
    name: string | null;
    slug: string | null;
    url: string | null;
  } | null;
  author?: {
    id?: number | null;
    name: string | null;
    slug: string | null;
    url: string | null;
    job_title?: string | null;
    short_bio?: string | null;
    avatar?: MediaAsset | null;
    article_count?: number | null;
    editorial_focus?: Array<{
      id: number;
      name: string;
      slug: string;
      url: string;
    }>;
  } | null;
  published_at: string | null;
  updated_at?: string | null;
  reading_time_minutes: number;
};

export type ArticleCardItem = AuthorArticle;

export type TaxonomySeo = {
  title: string | null;
  meta_description: string | null;
  canonical_url: string | null;
  og_title: string | null;
  og_description: string | null;
  og_image: MediaAsset | null;
  breadcrumbs?: Array<Record<string, unknown>> | null;
};

export type Category = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  url: string;
  updated_at?: string | null;
  seo: TaxonomySeo;
};

export type Tag = {
  id: number;
  name: string;
  slug: string;
  url: string;
  updated_at?: string | null;
  seo: TaxonomySeo;
};

export type ArticleBlock = {
  id?: number;
  type: "paragraph" | "heading" | "image" | "quote" | "embed" | "ad" | "gallery";
  data: Record<string, unknown>;
  sort_order: number;
};

export type ArticleDetail = ArticleCardItem & {
  body_html: string | null;
  blocks: ArticleBlock[];
  old_wordpress_id: number | null;
  old_url: string | null;
  status: string;
  presentation_type?: "standard" | "event_guide" | "interview" | "column";
  guide: {
    meta: {
      period_label: string | null;
      city_label: string | null;
      last_verified_at: string | null;
      event_count: number;
    };
    events: Array<{
      id: string;
      title: string;
      start_date: string | null;
      end_date: string | null;
      time: string | null;
      location: string | null;
      price_label: string | null;
      short_description: string | null;
      source_url: string | null;
      source_label: string | null;
      booking_url: string | null;
      booking_label: string | null;
      display_order: number;
    }>;
    practical_notes: string[];
    sources: Array<{
      label: string;
      url: string;
    }>;
  } | null;
  is_ai_generated: boolean;
  is_ai_assisted: boolean;
  tags: Tag[];
  seo: TaxonomySeo & {
    breadcrumbs: Array<Record<string, unknown>> | null;
  };
  sponsored_content: {
    sponsor_name: string;
    sponsor_logo: MediaAsset | null;
    sponsor_url: string | null;
    disclosure_label: string;
    campaign_start_at: string | null;
    campaign_end_at: string | null;
    reportable_views: number;
    reportable_clicks: number;
  } | null;
};

export type ArchiveResponse<TArchive> = {
  data: {
    articles: PaginatedResponse<ArticleCardItem>;
  } & TArchive;
};

export type SearchResponse = {
  data: {
    query: string;
    filters: {
      selected: {
        category: string | null;
        tag: string | null;
        author: string | null;
        type: string | null;
        sponsored: boolean | null;
        published_from: string | null;
        published_to: string | null;
      };
      categories: Category[];
      tags: Tag[];
      authors: AuthorListItem[];
    };
    articles: PaginatedResponse<ArticleCardItem>;
  };
};

export type HomepageSection = {
  key: string;
  name: string;
  type: string;
  title: string;
  description: string | null;
  layout: "hero" | "grid" | "compact" | "list";
  ad_zone_after: string | null;
  category: Category | null;
  articles: ArticleCardItem[];
  promo_cards: Array<{
    title: string;
    href: string;
    image_url: string;
    alt: string;
  }>;
};

export type HomepageResponse = {
  data: {
    sections: HomepageSection[];
  };
};

export type PaginatedResponse<T> = {
  data: T[];
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
  };
};

export type AdPlacement = {
  id: number;
  zone_key: string;
  name: string;
  type: "image" | "html" | "script" | "native";
  image: MediaAsset | null;
  html: string | null;
  click_url: string | null;
  click_tracking_url: string | null;
  impression_tracking_url: string;
  delivery_token: string;
  delivery_token_expires_at: string;
  width: number | null;
  height: number | null;
  is_fallback: boolean;
};

export type AdProduct = {
  id?: number;
  zone_key: string;
  name: string;
  description: string | null;
  page_context: string;
  allowed_sizes: number[][];
  availability_url?: string;
  prices: Array<{
    duration_weeks: 1 | 2 | 4;
    creative_type: "image" | "html" | "script";
    price_denars: number;
    political_multiplier: string | number;
  }>;
};

export type AdBookingLineItemInput = {
  zone_key: string;
  duration_weeks: 1 | 2 | 4;
  start_date: string;
  creative_type: "image" | "html" | "script";
  click_url?: string | null;
  width: number;
  height: number;
  html?: string | null;
  image_id?: number | null;
  image_token?: string | null;
  is_political_or_sensitive: boolean;
};

export type AdBookingRequestInput = {
  billing: {
    company_name: string;
    tax_number: string;
    registration_number?: string | null;
    address: string;
    city: string;
    country: string;
  };
  contact: {
    name: string;
    email: string;
    phone?: string | null;
  };
  discount_code?: string | null;
  items: AdBookingLineItemInput[];
};

export type AdBookingRequestResponse = {
  data: {
    id: number;
    reference: string;
    status: string;
    payment_status: string;
    subtotal_denars: number;
    discount_denars: number;
    tax_denars: number;
    total_denars: number;
    hold_expires_at: string | null;
  };
};

export type CustomerAdDashboardCustomer = {
  id: number;
  company_name: string;
  tax_number: string | null;
  contact_name: string | null;
  contact_email: string | null;
  status: string;
};

export type CustomerAdCreativeRevision = {
  id: number;
  status: string;
  creative_type: "image" | "html" | "script";
  rejection_reason: string | null;
  technical_review_required: boolean;
  created_at: string | null;
};

export type CustomerAdRenewalOption = {
  duration_weeks: 1 | 2 | 4;
  available: boolean;
  price_denars: number | null;
};

export type CustomerAdBookingItem = {
  id: number;
  zone_name: string | null;
  zone_key: string | null;
  duration_weeks: number;
  start_at: string | null;
  end_at: string | null;
  start_date: string | null;
  end_date: string | null;
  creative_type: "image" | "html" | "script";
  width: number;
  height: number;
  status: string;
  rejection_reason: string | null;
  technical_review_required: boolean;
  price_denars: number;
  impressions: number;
  clicks: number;
  ctr: number;
  can_renew: boolean;
  renewal_options: CustomerAdRenewalOption[];
  revisions: CustomerAdCreativeRevision[];
};

export type CustomerAdBooking = {
  id: number;
  reference: string;
  status: string;
  payment_status: string;
  rejection_reason: string | null;
  total_denars: number;
  hold_expires_at: string | null;
  paid_at: string | null;
  items: CustomerAdBookingItem[];
};

export type CustomerAdInvoice = {
  id: number;
  booking_reference: string | null;
  number: string;
  type: string;
  status: string;
  total_denars: number;
  issued_at: string | null;
  due_at: string | null;
};

export type CustomerAdDashboard = {
  customer: CustomerAdDashboardCustomer;
  bookings: CustomerAdBooking[];
  invoices: CustomerAdInvoice[];
};

export type CustomerLoginResponse = {
  data: {
    user: {
      id: number;
      name: string;
      email: string;
    };
    customer: {
      id: number;
      company_name: string;
    };
  };
};

export type CustomerRenewalResponse = {
  data: {
    line_item_id: number;
    renewal_booking_request_id: number;
    reference: string;
    status: string;
    payment_status: string;
    total_denars: number;
  };
};

export type CustomerAdCreativeRevisionInput = {
  creative_type: "image" | "html" | "script";
  click_url?: string | null;
  width: number;
  height: number;
  html?: string | null;
  image_id?: number | null;
};

export type CustomerAdCreativeRevisionResponse = {
  data: {
    id: number;
    line_item_id: number;
    status: string;
    technical_review_required: boolean;
  };
};

export type AdPlacementContext = {
  articleId?: number;
  category?: string | null;
  categoryId?: number | null;
  device?: "desktop" | "tablet" | "mobile";
};

export type MenuItem = {
  id: number;
  title: string;
  link_type: string;
  url: string | null;
  opens_in_new_tab: boolean;
  sort_order: number;
  children: MenuItem[];
};

export type Menu = {
  id: number | null;
  name: string | null;
  location: string;
  items: MenuItem[];
};

export type PublicSiteSettings = {
  site_name?: string | string[] | null;
  site_url?: string | string[] | null;
  default_meta_title?: string | string[] | null;
  default_meta_description?: string | string[] | null;
  default_og_image?: string | string[] | null;
  [key: string]: string | string[] | Record<string, string> | null | undefined;
};

const resolvedApiBaseUrl = apiBaseUrl();

const liveEditorialRequest = {
  cache: "no-store" as const,
  next: {
    revalidate: 0,
  },
};

export class ApiRequestError extends Error {
  payload: unknown;
  status: number;

  constructor(status: number, payload: unknown) {
    const message =
      typeof payload === "object"
        && payload !== null
        && "message" in payload
        && typeof payload.message === "string"
        ? payload.message
        : `API request failed with status ${status}`;

    super(message);
    this.name = "ApiRequestError";
    this.payload = payload;
    this.status = status;
  }
}

async function errorPayload(response: Response): Promise<unknown> {
  const contentType = response.headers.get("content-type") ?? "";

  if (!contentType.includes("application/json")) {
    return null;
  }

  return response.json().catch(() => null);
}

export async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${resolvedApiBaseUrl}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...init?.headers,
    },
    next: {
      revalidate: 60,
      ...init?.next,
    },
  });

  if (!response.ok) {
    throw new ApiRequestError(response.status, await errorPayload(response));
  }

  return response.json() as Promise<T>;
}

export async function apiFetchNullable<T>(path: string, init?: RequestInit): Promise<T | null> {
  const response = await fetch(`${resolvedApiBaseUrl}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...init?.headers,
    },
    next: {
      revalidate: 60,
      ...init?.next,
    },
  });

  if (response.status === 404) {
    return null;
  }

  if (!response.ok) {
    throw new ApiRequestError(response.status, await errorPayload(response));
  }

  return response.json() as Promise<T>;
}

export async function getApiStatus(): Promise<ApiStatus | null> {
  try {
    return await apiFetch<ApiStatus>("/v1/status");
  } catch {
    return null;
  }
}

export async function getAuthors(page = 1, perPage = 12, options: { search?: string; sort?: string } = {}): Promise<AuthorDirectoryResponse> {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });

  if (options.search) params.set("search", options.search);
  if (options.sort) params.set("sort", options.sort);

  return apiFetch<AuthorDirectoryResponse>(`/authors?${params.toString()}`, liveEditorialRequest);
}

export async function getAuthor(slug: string): Promise<{ data: AuthorDetail } | null> {
  return apiFetchNullable<{ data: AuthorDetail }>(`/authors/${slug}`, liveEditorialRequest);
}

export async function getTeamMembers(): Promise<TeamMembersResponse> {
  return apiFetch<TeamMembersResponse>("/team-members");
}

export async function getTeamOrgChart(): Promise<TeamOrgChartResponse> {
  return apiFetch<TeamOrgChartResponse>("/team/org-chart");
}

export async function getAuthorArticles(
  slug: string,
  page = 1,
): Promise<PaginatedResponse<AuthorArticle>> {
  return apiFetch<PaginatedResponse<AuthorArticle>>(`/authors/${slug}/articles?page=${page}&per_page=6`, liveEditorialRequest);
}

export async function getAdPlacement(zoneKey: string, context: AdPlacementContext = {}): Promise<AdPlacement | null> {
  const params = new URLSearchParams();

  if (context.device) {
    params.set("device", context.device);
  }

  if (context.category) {
    params.set("category", context.category);
  }

  if (context.categoryId) {
    params.set("category_id", String(context.categoryId));
  }

  if (context.articleId) {
    params.set("article_id", String(context.articleId));
  }

  const query = params.toString();
  const response = await apiFetchNullable<{ data: AdPlacement | null }>(
    `/ads/${zoneKey}${query ? `?${query}` : ""}`,
    {
      cache: "no-store",
      next: {
        revalidate: 0,
      },
    },
  );

  return response?.data ?? null;
}

export async function getAdProducts(): Promise<AdProduct[]> {
  const response = await apiFetch<{ data: AdProduct[] }>("/ad-products");

  return response.data;
}

export async function submitAdBookingRequest(input: AdBookingRequestInput): Promise<AdBookingRequestResponse> {
  return apiFetch<AdBookingRequestResponse>("/ad-booking-requests", {
    method: "POST",
    cache: "no-store",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(input),
    next: {
      revalidate: 0,
    },
  });
}

export function uploadPublicAdCreative(file: File, onProgress: (progress: number) => void): Promise<{ data: AdCreativeUpload }> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    const formData = new FormData();
    formData.append("file", file);
    request.open("POST", `${resolvedApiBaseUrl}/ad-booking-creatives`);
    request.setRequestHeader("Accept", "application/json");
    request.upload.addEventListener("progress", (event) => {
      if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
    });
    request.addEventListener("load", () => {
      let payload: unknown = null;
      try {
        payload = request.responseText ? JSON.parse(request.responseText) : null;
      } catch {
        payload = { message: "Серверот врати неочекуван одговор." };
      }
      if (request.status >= 200 && request.status < 300) resolve(payload as { data: AdCreativeUpload });
      else reject(new ApiRequestError(request.status, payload));
    });
    request.addEventListener("error", () => reject(new Error("Не можеме да ја прикачиме сликата. Обидете се повторно.")));
    request.send(formData);
  });
}

export async function removePublicAdCreative(uploadToken: string): Promise<void> {
  await apiFetch("/ad-booking-creatives", {
    method: "DELETE",
    cache: "no-store",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ upload_token: uploadToken }),
  });
}

export async function getCustomerAdCreativeMedia(): Promise<AdCreativeUpload[] | null> {
  try {
    const response = await customerFetch<{ data: AdCreativeUpload[] }>("/customer/media/ad-creatives");
    return response.data;
  } catch (error) {
    if (error instanceof ApiRequestError && [401, 403].includes(error.status)) return null;
    throw error;
  }
}

export async function claimCustomerAdCreative(mediaId: number): Promise<AdCreativeUpload> {
  const response = await customerMutatingFetch<{ data: AdCreativeUpload }>(`/customer/media/ad-creatives/${mediaId}/claim`, {
    method: "POST",
    body: JSON.stringify({}),
  });

  return response.data;
}

async function customerCsrfToken(): Promise<string> {
  const response = await fetch(`${resolvedApiBaseUrl}/customer/csrf-token`, {
    credentials: "include",
    headers: {
      Accept: "application/json",
    },
    cache: "no-store",
  });

  if (!response.ok) {
    throw new ApiRequestError(response.status, await errorPayload(response));
  }

  const payload = await response.json() as { data?: { csrf_token?: string } };

  return payload.data?.csrf_token ?? "";
}

async function customerFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${resolvedApiBaseUrl}${path}`, {
    ...init,
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...init?.headers,
    },
    cache: "no-store",
  });

  if (!response.ok) {
    throw new ApiRequestError(response.status, await errorPayload(response));
  }

  return response.json() as Promise<T>;
}

async function customerMutatingFetch<T>(path: string, init: RequestInit): Promise<T> {
  const csrfToken = await customerCsrfToken();

  return customerFetch<T>(path, {
    ...init,
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": csrfToken,
      ...init.headers,
    },
  });
}

async function customerFormFetch<T>(path: string, formData: FormData): Promise<T> {
  const csrfToken = await customerCsrfToken();

  return customerFetch<T>(path, {
    method: "POST",
    body: formData,
    headers: {
      "X-CSRF-TOKEN": csrfToken,
    },
  });
}

export async function loginCustomer(email: string, password: string): Promise<CustomerLoginResponse> {
  return customerMutatingFetch<CustomerLoginResponse>("/customer/login", {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
}

export async function logoutCustomer(): Promise<void> {
  await customerMutatingFetch<{ data: { logged_out: boolean } }>("/customer/logout", {
    method: "POST",
    body: JSON.stringify({}),
  });
}

export async function getCustomerAdDashboard(): Promise<CustomerAdDashboard | null> {
  try {
    const response = await customerFetch<{ data: CustomerAdDashboard }>("/customer/ads");

    return response.data;
  } catch (error) {
    if (error instanceof ApiRequestError && [401, 403].includes(error.status)) {
      return null;
    }

    throw error;
  }
}

export async function getCustomerAdInvoices(): Promise<CustomerAdInvoice[]> {
  const response = await customerFetch<{ data: CustomerAdInvoice[] }>("/customer/invoices");

  return response.data;
}

export async function renewCustomerAdBooking(
  lineItemId: number,
  durationWeeks: 1 | 2 | 4,
): Promise<CustomerRenewalResponse> {
  return customerMutatingFetch<CustomerRenewalResponse>(`/customer/bookings/${lineItemId}/renew`, {
    method: "POST",
    body: JSON.stringify({ duration_weeks: durationWeeks }),
  });
}

export async function uploadCustomerAdCreative(file: File, altText?: string): Promise<{ data: MediaAsset }> {
  const formData = new FormData();
  formData.append("file", file);

  if (altText?.trim()) {
    formData.append("alt_text", altText.trim());
  }

  return customerFormFetch<{ data: MediaAsset }>("/customer/media/ad-creatives", formData);
}

export async function submitCustomerAdCreativeRevision(
  lineItemId: number,
  input: CustomerAdCreativeRevisionInput,
): Promise<CustomerAdCreativeRevisionResponse> {
  return customerMutatingFetch<CustomerAdCreativeRevisionResponse>(`/customer/creatives/${lineItemId}/revision`, {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export async function getMenu(location: string): Promise<Menu | null> {
  const response = await apiFetchNullable<{ data: Menu }>(`/menus/${location}`);

  return response?.data ?? null;
}

export async function getPublicSiteSettings(): Promise<PublicSiteSettings | null> {
  try {
    const response = await apiFetch<{ data: PublicSiteSettings }>("/site-settings/public");

    return response.data;
  } catch {
    return null;
  }
}

export type ArticleListFilters = {
  author?: string;
  category?: string;
  tag?: string;
  type?: string;
  sponsored?: boolean | string;
  published_from?: string;
  published_to?: string;
};

export async function getArticles(
  page = 1,
  perPage = 12,
  filters: ArticleListFilters = {},
): Promise<PaginatedResponse<ArticleCardItem>> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });

  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== "") {
      params.set(key, String(value));
    }
  }

  return apiFetch<PaginatedResponse<ArticleCardItem>>(`/articles?${params.toString()}`, liveEditorialRequest);
}

export async function getEvents(page = 1, perPage = 12): Promise<PaginatedResponse<ArticleCardItem>> {
  return apiFetch<PaginatedResponse<ArticleCardItem>>(`/events?page=${page}&per_page=${perPage}`, liveEditorialRequest);
}

export async function getHomepage(): Promise<HomepageResponse | null> {
  try {
    return await apiFetch<HomepageResponse>("/homepage", liveEditorialRequest);
  } catch {
    return null;
  }
}

export async function getArticle(slug: string): Promise<{ data: ArticleDetail } | null> {
  return apiFetchNullable<{ data: ArticleDetail }>(`/articles/${slug}`, liveEditorialRequest);
}

export async function getArticleSignedPreview(previewPath: string): Promise<{ data: ArticleDetail } | null> {
  const apiPath = signedPreviewApiPath(previewPath);

  if (!apiPath) {
    return null;
  }

  return apiFetchNullable<{ data: ArticleDetail }>(apiPath, liveEditorialRequest);
}

export async function getRelatedArticles(slug: string, limit = 3): Promise<ArticleCardItem[]> {
  const response = await apiFetchNullable<{ data: ArticleCardItem[] }>(
    `/articles/${slug}/related?limit=${limit}`,
    liveEditorialRequest,
  );

  return response?.data ?? [];
}

export async function getCategoryArticles(
  slug: string,
  page = 1,
): Promise<ArchiveResponse<{ category: Category }> | null> {
  return apiFetchNullable<ArchiveResponse<{ category: Category }>>(
    `/categories/${slug}/articles?page=${page}`,
    liveEditorialRequest,
  );
}

export async function getCategories(page = 1, perPage = 24): Promise<PaginatedResponse<Category>> {
  return apiFetch<PaginatedResponse<Category>>(`/categories?page=${page}&per_page=${perPage}`);
}

export async function getTagArticles(
  slug: string,
  page = 1,
): Promise<ArchiveResponse<{ tag: Tag }> | null> {
  return apiFetchNullable<ArchiveResponse<{ tag: Tag }>>(`/tags/${slug}/articles?page=${page}`, liveEditorialRequest);
}

export async function getTags(page = 1, perPage = 48): Promise<PaginatedResponse<Tag>> {
  return apiFetch<PaginatedResponse<Tag>>(`/tags?page=${page}&per_page=${perPage}`);
}

export async function searchArticles(
  query: string,
  page = 1,
  filters: ArticleListFilters = {},
): Promise<SearchResponse> {
  const params = new URLSearchParams({
    query,
    page: String(page),
  });

  for (const [key, value] of Object.entries(filters)) {
    if (value) {
      params.set(key, String(value));
    }
  }

  return apiFetch<SearchResponse>(`/search?${params.toString()}`, liveEditorialRequest);
}
