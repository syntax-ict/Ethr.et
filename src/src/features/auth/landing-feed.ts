import axios from "axios";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";
import type { TenantRequest } from "@/lib/auth/use-auth-host-context";

/** `GET /auth/tenant-landing/feed`: what the organisation shows the public. */
export type LandingFeed =
  operations["landingFeed.index"]["responses"][200]["content"]["application/json"];
export type LandingVacancy =
  operations["landingFeed.vacancy"]["responses"][200]["content"]["application/json"];
export type LandingAnnouncement =
  operations["landingFeed.announcement"]["responses"][200]["content"]["application/json"];
/** `GET /auth/tenant-landing/news`: one page of the organisation's news. */
export type LandingNewsPage =
  operations["landingFeed.news"]["responses"][200]["content"]["application/json"];
export type LandingNewsItem = LandingNewsPage["data"][number];
export type NewsCategory = NonNullable<
  NonNullable<operations["landingFeed.news"]["parameters"]["query"]>["category"]
>;
export const NEWS_CATEGORIES: readonly NewsCategory[] = [
  "news",
  "notice",
  "event",
  "deadline",
];

/**
 * A public request to the organisation the sign-in page is for, asked the
 * same way its context was found (see `tenantRequest`): never through
 * apiClient off the organisation's own host, where the stored tenant header
 * would name whichever organisation this browser used last.
 */
async function getPublic<T>(request: TenantRequest, path: string): Promise<T> {
  if (request.mode === "client") {
    return (await apiClient.get<T>(path)).data;
  }

  const headers: Record<string, string> = { Accept: "application/json" };
  if (request.mode === "entry") headers["X-Tenant"] = request.slug;

  return (
    await axios.get<T>(`/api/v1${path}`, { withCredentials: true, headers })
  ).data;
}

function requestKey(request: TenantRequest | null): string | null {
  if (!request) return null;
  return request.mode === "entry" ? `entry:${request.slug}` : request.mode;
}

export function useLandingFeed(request: TenantRequest | null) {
  return useQuery<LandingFeed>({
    queryKey: ["auth", "landing-feed", requestKey(request)],
    queryFn: () =>
      getPublic<LandingFeed>(request!, "/auth/tenant-landing/feed"),
    enabled: request !== null,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
}

export interface NewsQuery {
  q: string;
  category: NewsCategory | null;
  page: number;
}

/**
 * One page of the organisation's public news, as the reader filtered it.
 * The previous page stays on screen while the next loads, so paging and
 * filtering do not flash an empty grid.
 */
export function useLandingNews(
  request: TenantRequest | null,
  query: NewsQuery,
) {
  const params = new URLSearchParams();
  if (query.q.trim()) params.set("q", query.q.trim());
  if (query.category) params.set("category", query.category);
  if (query.page > 1) params.set("page", String(query.page));
  const qs = params.toString();

  return useQuery<LandingNewsPage>({
    queryKey: ["auth", "landing-news", requestKey(request), qs],
    queryFn: () =>
      getPublic<LandingNewsPage>(
        request!,
        `/auth/tenant-landing/news${qs ? `?${qs}` : ""}`,
      ),
    enabled: request !== null,
    retry: false,
    placeholderData: keepPreviousData,
    staleTime: 60 * 1000,
  });
}

export function useLandingVacancy(
  request: TenantRequest | null,
  publicId: string | null,
) {
  return useQuery<LandingVacancy>({
    queryKey: ["auth", "landing-vacancy", requestKey(request), publicId],
    queryFn: () =>
      getPublic<LandingVacancy>(
        request!,
        `/auth/tenant-landing/vacancies/${encodeURIComponent(publicId!)}`,
      ),
    enabled: request !== null && publicId !== null,
    retry: false,
  });
}

export function useLandingAnnouncement(
  request: TenantRequest | null,
  publicId: string | null,
) {
  return useQuery<LandingAnnouncement>({
    queryKey: ["auth", "landing-announcement", requestKey(request), publicId],
    queryFn: () =>
      getPublic<LandingAnnouncement>(
        request!,
        `/auth/tenant-landing/announcements/${encodeURIComponent(publicId!)}`,
      ),
    enabled: request !== null && publicId !== null,
    retry: false,
  });
}
