import { useQuery } from "@tanstack/react-query";
import type { components } from "@/api/generated";
import {
  CONTENT_SNAPSHOT,
  CONTENT_SNAPSHOT_GENERATED_AT,
  type MarketingSection,
} from "@/lib/marketing/content";

/*
 * No static import of `@/api/client` in this module, on purpose. It is the
 * only API code every public page loads, and importing the shared axios
 * instance here put the HTTP client — 18 KB gzipped, plus the authenticated
 * app's interceptors — on the landing page of a site whose visitors are not
 * signed in (measured from the built `/en` page, 2026-10-03).
 */

// The site content lives in its own module so the authenticated app's sidebar
// can read the product mark without loading this one, which carries the
// landing page's content snapshot.
export {
  EMPTY_SITE_CONTENT,
  pickLocalised,
  useProductMark,
  useSiteContent,
  type ProductMark,
  type SiteContent,
} from "./site-content";

export type TrustedOrganisation =
  components["schemas"]["TrustedOrganisationResource"];

/**
 * The organisations ETHR may name on its public site: active customers that
 * agreed to be named and that the platform has not hidden. Empty until the
 * fetch resolves, and the section that renders them disappears while it is.
 */
export function useTrustedBy(): TrustedOrganisation[] {
  const { data } = useQuery<TrustedOrganisation[]>({
    queryKey: ["trusted-by"],
    // A plain public GET, like site-content: no axios on the landing page.
    queryFn: async () => {
      const response = await fetch("/api/v1/trusted-by", {
        headers: { Accept: "application/json" },
      });
      if (!response.ok) {
        throw new Error(`trusted-by answered ${response.status}`);
      }
      const body = (await response.json()) as { data: TrustedOrganisation[] };
      return body.data;
    },
    staleTime: 60_000,
  });

  return data ?? [];
}

/**
 * The public contact form. `website` is the honeypot: empty for a person,
 * filled by a form-filler bot; the API answers 201 either way.
 */
export async function sendContactMessage(
  payload: components["schemas"]["ContactRequest"] & { website?: string },
): Promise<void> {
  // The shared client, loaded when someone actually sends the form. Not a
  // hand-rolled fetch: `statefulApi()` CSRF-checks this POST, and axios is what
  // copies the XSRF-TOKEN cookie into the header. `lib/echo.ts` records what
  // the fetch version of that cost — a 419 on every request, swallowed.
  const { apiClient } = await import("@/api/client");
  await apiClient.post("/contact", payload);
}

/**
 * The landing page's sections and rows, owned by the platform operator.
 *
 * Seeded from the build-time snapshot so the prerendered HTML and the first
 * paint both carry real content, and dated to when that snapshot was taken so
 * the page refetches on mount and an operator's edit replaces it within a
 * minute. The hook never returns undefined: there is no loading state on the
 * page at any point, by design.
 */
export function useMarketingContent(): { sections: MarketingSection[] } {
  const { data } = useQuery<{ sections: MarketingSection[] }>({
    queryKey: ["marketing-content"],
    // A plain public GET, like site-content: no axios on the landing page.
    queryFn: async () => {
      const response = await fetch("/api/v1/marketing-content", {
        headers: { Accept: "application/json" },
      });
      if (!response.ok) {
        throw new Error(`marketing-content answered ${response.status}`);
      }
      const body = (await response.json()) as {
        data: { sections: MarketingSection[] };
      };
      return body.data;
    },
    initialData: CONTENT_SNAPSHOT,
    initialDataUpdatedAt: CONTENT_SNAPSHOT_GENERATED_AT,
    staleTime: 60_000,
  });

  return data ?? CONTENT_SNAPSHOT;
}
