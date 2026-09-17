import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

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
  tagline: string | null;
  tagline_am: string | null;
  logo_url: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  office_address: string | null;
  office_address_am: string | null;
  social_linkedin: string | null;
  social_x: string | null;
  social_facebook: string | null;
  metric_organisations: number | null;
  metric_employees: number | null;
  metric_uptime_note: string | null;
  metric_uptime_note_am: string | null;
  /**
   * All six arrive together or not at all. `SiteContentResource` refuses to
   * publish a quote without an author and a recorded consent date, so a
   * half-entered draft never reaches the page — `testimonial_quote` being
   * non-null is the whole condition for rendering the section.
   */
  testimonial_quote: string | null;
  testimonial_quote_am: string | null;
  testimonial_author: string | null;
  testimonial_role: string | null;
  testimonial_role_am: string | null;
  testimonial_organisation: string | null;
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
  tagline: null,
  tagline_am: null,
  logo_url: null,
  contact_email: null,
  contact_phone: null,
  office_address: null,
  office_address_am: null,
  social_linkedin: null,
  social_x: null,
  social_facebook: null,
  metric_organisations: null,
  metric_employees: null,
  metric_uptime_note: null,
  metric_uptime_note_am: null,
  testimonial_quote: null,
  testimonial_quote_am: null,
  testimonial_author: null,
  testimonial_role: null,
  testimonial_role_am: null,
  testimonial_organisation: null,
};

export function useSiteContent(): SiteContent {
  const { data } = useQuery<SiteContent>({
    queryKey: ["site-content"],
    queryFn: async () => {
      const { data } = await apiClient.get("/site-content");
      return data.data;
    },
    // The backend caches for five minutes and invalidates on write, so a short
    // client stale time costs little and means an operator's correction reaches
    // a tab that is already open.
    staleTime: 60_000,
  });

  // Never undefined, so no caller needs a loading branch for content that is
  // allowed to be absent anyway.
  return data ?? EMPTY_SITE_CONTENT;
}

/**
 * Picks the reader's language, falling back to English rather than to nothing.
 *
 * An operator who filled the English column and not the Amharic one has not
 * asked for the field to disappear — and Amharic is the locale the server
 * renders by default, so a null-on-missing rule would blank these for most
 * visitors.
 */
export function pickLocalised(
  locale: string,
  am: string | null,
  en: string | null,
): string | null {
  return locale === "am" && am ? am : en;
}
