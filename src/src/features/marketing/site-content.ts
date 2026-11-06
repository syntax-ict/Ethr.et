import { useQuery } from "@tanstack/react-query";

/**
 * The facts the public site states about ETHR, owned by the platform admin.
 *
 * Mirrors `SiteContentResource`, which publishes a whitelist — `platform_settings`
 * also holds the bank account every tenant pays into, and this endpoint is
 * unauthenticated.
 */
export interface SiteContent {
  platform_name: string | null;
  platform_name_am: string | null;
  platform_name_om: string | null;
  tagline: string | null;
  tagline_am: string | null;
  tagline_om: string | null;
  logo_url: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  office_address: string | null;
  office_address_am: string | null;
  office_address_om: string | null;
  social_linkedin: string | null;
  social_x: string | null;
  social_facebook: string | null;
  metric_organisations: number | null;
  metric_employees: number | null;
  metric_uptime_note: string | null;
  metric_uptime_note_am: string | null;
  metric_uptime_note_om: string | null;
  /**
   * All six arrive together or not at all. `SiteContentResource` refuses to
   * publish a quote without an author and a recorded consent date, so a
   * half-entered draft never reaches the page — `testimonial_quote` being
   * non-null is the whole condition for rendering the section.
   */
  testimonial_quote: string | null;
  testimonial_quote_am: string | null;
  testimonial_quote_om: string | null;
  testimonial_author: string | null;
  testimonial_role: string | null;
  testimonial_role_am: string | null;
  testimonial_role_om: string | null;
  testimonial_organisation: string | null;
  /**
   * The "Powered by ETHR" mark at the foot of every organisation's sidebar,
   * decided by the platform operator in the console. A null label or link
   * means the built-in wording, translated, and the public site.
   */
  product_mark_enabled: boolean;
  product_mark_label: string | null;
  product_mark_label_am: string | null;
  product_mark_label_om: string | null;
  product_mark_url: string | null;
}

/**
 * Everything null — what the marketing pages render before the fetch resolves,
 * and what they keep rendering if it never does.
 *
 * That is the design rather than a fallback: every field here is a claim, and a
 * claim with nothing behind it belongs off the page rather than defaulting to
 * something plausible. The landing page's previous default was "500+
 * organisations".
 */
export const EMPTY_SITE_CONTENT: SiteContent = {
  platform_name: null,
  platform_name_am: null,
  platform_name_om: null,
  tagline: null,
  tagline_am: null,
  tagline_om: null,
  logo_url: null,
  contact_email: null,
  contact_phone: null,
  office_address: null,
  office_address_am: null,
  office_address_om: null,
  social_linkedin: null,
  social_x: null,
  social_facebook: null,
  metric_organisations: null,
  metric_employees: null,
  metric_uptime_note: null,
  metric_uptime_note_am: null,
  metric_uptime_note_om: null,
  testimonial_quote: null,
  testimonial_quote_am: null,
  testimonial_quote_om: null,
  testimonial_author: null,
  testimonial_role: null,
  testimonial_role_am: null,
  testimonial_role_om: null,
  testimonial_organisation: null,
  // On, like the column: the mark is the product's default, and a failed
  // fetch is not an operator's decision to hide it.
  product_mark_enabled: true,
  product_mark_label: null,
  product_mark_label_am: null,
  product_mark_label_om: null,
  product_mark_url: null,
};

/**
 * The last payload, kept across page loads. The backend caches it for five
 * minutes, so a copy younger than that is what the server would send; a
 * fresh copy means no request on this load (dashboard review, 2026-11-03,
 * PR 11). Storage can be absent or full; either way the fetch still runs.
 */
const STORAGE_KEY = "ethr.site-content";
const FRESH_FOR_MS = 5 * 60_000;

function storedSiteContent(): { data: SiteContent; at: number } | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { data?: SiteContent; at?: number };
    if (!parsed.data || typeof parsed.at !== "number") return null;
    return { data: parsed.data, at: parsed.at };
  } catch {
    return null;
  }
}

function storeSiteContent(data: SiteContent): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ data, at: Date.now() }));
  } catch {
    // Private mode or a full store: the next load fetches, as before.
  }
}

const siteContentQuery = {
  queryKey: ["site-content"],
  // A plain GET: public, unauthenticated, not CSRF-checked, and the same for
  // every tenant and language (the payload carries both languages).
  queryFn: async (): Promise<SiteContent> => {
    const response = await fetch("/api/v1/site-content", {
      headers: { Accept: "application/json" },
    });
    if (!response.ok) {
      throw new Error(`site-content answered ${response.status}`);
    }
    const body = (await response.json()) as { data: SiteContent };
    storeSiteContent(body.data);
    return body.data;
  },
  // The backend caches for five minutes and invalidates on write, so the
  // client keeps an answer for the same five minutes: an operator's
  // correction reaches an open tab when the backend's own cache would have
  // served it anyway.
  staleTime: FRESH_FOR_MS,
  // A stored copy younger than that stands in for the first answer, dated so
  // React Query knows when it was fetched and refetches once it is stale.
  initialData: () => {
    const stored = storedSiteContent();
    return stored && Date.now() - stored.at < FRESH_FOR_MS
      ? stored.data
      : undefined;
  },
  initialDataUpdatedAt: () => storedSiteContent()?.at,
} as const;

export function useSiteContent(): SiteContent {
  const { data } = useQuery<SiteContent>(siteContentQuery);

  // Never undefined, so no caller needs a loading branch for content that is
  // allowed to be absent anyway.
  return data ?? EMPTY_SITE_CONTENT;
}

/** The sidebar's product mark, resolved: what it says, where it goes. */
export interface ProductMark {
  /** The operator's wording in the reader's language, or null for the default. */
  label: string | null;
  /** The operator's link, or null for the public site. */
  url: string | null;
  /** The platform logo the public site shows, when one is set. */
  logoUrl: string | null;
}

/**
 * The "Powered by ETHR" mark, or null while it should not be drawn.
 *
 * Null until the payload arrives, unlike `useSiteContent`: drawing the default
 * first would flash the mark into every page of a platform whose operator
 * turned it off. A failed fetch falls back to the default, which is on.
 */
export function useProductMark(locale: string): ProductMark | null {
  const { data, isError } = useQuery<SiteContent>(siteContentQuery);
  const site = data ?? (isError ? EMPTY_SITE_CONTENT : null);

  if (!site || site.product_mark_enabled === false) return null;

  return {
    label: pickLocalised(
      locale,
      site.product_mark_label_am,
      site.product_mark_label,
      site.product_mark_label_om,
    ),
    url: site.product_mark_url,
    logoUrl: site.logo_url,
  };
}

/**
 * Picks the reader's language, falling back to English rather than to nothing.
 *
 * `om` is optional so a field that has no Oromo twin can still be picked;
 * every platform-written field has one since 2026-11-03.
 *
 * An operator who filled the English column and not the Amharic one has not
 * asked for the field to disappear — and Amharic is the locale the server
 * renders by default, so a null-on-missing rule would blank these for most
 * visitors.
 */
export function pickLocalised(
  locale: string,
  am: string | null | undefined,
  en: string | null,
  om?: string | null,
): string | null {
  if (locale === "am" && am) return am;
  if (locale === "om" && om) return om;
  return en;
}
