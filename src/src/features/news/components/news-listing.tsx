"use client";

import { useEffect, useState, type FormEvent } from "react";
import axios from "axios";
import { ChevronLeft, ChevronRight, Newspaper, Search, X } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import {
  NEWS_CATEGORIES,
  useLandingNews,
  type NewsCategory,
  type NewsQuery,
} from "@/features/auth/landing-feed";
import {
  PortalChrome,
  portalLinks,
} from "@/features/auth/components/portal-chrome";
import { NewsCard } from "./news-card";
import { NewsState } from "./news-state";

function readQuery(): NewsQuery {
  if (typeof window === "undefined") return { q: "", category: null, page: 1 };
  const params = new URLSearchParams(window.location.search);
  const category = params.get("category");
  const page = Number(params.get("page") ?? "1");
  return {
    q: (params.get("q") ?? "").slice(0, 100),
    category: NEWS_CATEGORIES.includes(category as NewsCategory)
      ? (category as NewsCategory)
      : null,
    page: Number.isInteger(page) && page > 0 ? page : 1,
  };
}

/**
 * The organisation's news page (`/news`): every story it published for the
 * public, the featured ones first, a page at a time, with a search and a
 * category filter. The same shared page for every organisation, drawn from
 * its own data; the filters live in the address, so a filtered list can be
 * shared and the back button walks back through it.
 */
export function NewsListing() {
  const { t } = useT();
  const host = useAuthHostContext();
  const [query, setQuery] = useState<NewsQuery>({
    q: "",
    category: null,
    page: 1,
  });
  const [draft, setDraft] = useState("");

  // The address is the source of the filters: read once it can be, and
  // again when the visitor steps back or forward through it.
  useEffect(() => {
    const sync = () => {
      const next = readQuery();
      setQuery(next);
      setDraft(next.q);
    };
    sync();
    window.addEventListener("popstate", sync);
    return () => window.removeEventListener("popstate", sync);
  }, []);

  function apply(next: NewsQuery) {
    setQuery(next);
    const params = new URLSearchParams(window.location.search);
    for (const [key, value] of [
      ["q", next.q.trim()],
      ["category", next.category ?? ""],
      ["page", next.page > 1 ? String(next.page) : ""],
    ] as const) {
      if (value) params.set(key, value);
      else params.delete(key);
    }
    const qs = params.toString();
    window.history.pushState(
      null,
      "",
      `${window.location.pathname}${qs ? `?${qs}` : ""}`,
    );
    if (next.page !== query.page) window.scrollTo({ top: 0 });
  }

  const news = useLandingNews(host.tenantRequest, query);
  const links = portalLinks(host.tenantLanding, host.carryOrganisation, false);
  const filtered = query.q.trim() !== "" || query.category !== null;

  useEffect(() => {
    if (!host.tenantName) return;
    const previous = document.title;
    document.title = `${t("news.title", "News")} · ${host.tenantName}`;
    return () => {
      document.title = previous;
    };
  }, [host.tenantName, t]);

  // No organisation behind this address, or it publishes no news page: the
  // page says so and offers the way home, rather than an empty grid.
  const missing =
    (!host.tenantContextLoading && host.context !== "tenant") ||
    (axios.isAxiosError(news.error) && news.error.response?.status === 404);

  function submitSearch(event: FormEvent) {
    event.preventDefault();
    apply({ ...query, q: draft, page: 1 });
  }

  const items = news.data?.data ?? [];
  const lead =
    !filtered && query.page === 1 && items[0]?.featured ? items[0] : null;
  const rest = lead ? items.slice(1) : items;
  const meta = news.data?.meta;
  const categories = (news.data?.categories ?? []).filter(
    (c): c is NewsCategory => NEWS_CATEGORIES.includes(c as NewsCategory),
  );

  return (
    <PortalChrome
      name={host.tenantName}
      brand={host.tenantBrand}
      landing={host.tenantLanding}
      links={missing ? { ...links, news: null } : links}
      current="news"
    >
      <div className="py-10 @lg:py-14">
        <nav
          aria-label={t("news.breadcrumb", "Breadcrumb")}
          className="text-sm text-muted-foreground"
        >
          <ol className="flex flex-wrap items-center gap-1.5">
            <li>
              <a
                href={links.home}
                className="rounded-sm hover:text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {host.tenantName ?? t("news.home", "Home")}
              </a>
            </li>
            <li aria-hidden="true">
              <ChevronRight className="h-3.5 w-3.5" />
            </li>
            <li aria-current="page" className="font-medium text-foreground">
              {t("news.title", "News")}
            </li>
          </ol>
        </nav>

        <div className="mt-4 flex flex-col gap-6 @lg:flex-row @lg:items-end @lg:justify-between">
          <div className="min-w-0">
            <h1 className="text-3xl font-bold tracking-tight text-foreground">
              {t("news.title", "News")}
            </h1>
            <p className="mt-2 max-w-prose text-base text-muted-foreground">
              {host.tenantName
                ? t("news.subtitle", "News and announcements from :name.", {
                    name: host.tenantName,
                  })
                : t("news.subtitle_plain", "News and announcements.")}
            </p>
          </div>

          {!missing && (
            <form
              role="search"
              onSubmit={submitSearch}
              className="flex w-full gap-2 @lg:w-80"
            >
              <label htmlFor="news-search" className="sr-only">
                {t("news.search_label", "Search news")}
              </label>
              <div className="relative min-w-0 flex-1">
                <Search
                  className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                  aria-hidden="true"
                />
                <Input
                  id="news-search"
                  type="search"
                  value={draft}
                  maxLength={100}
                  onChange={(e) => setDraft(e.target.value)}
                  placeholder={t("news.search_placeholder", "Search news")}
                  className="pl-9"
                />
              </div>
              <Button type="submit" variant="outline">
                {t("news.search", "Search")}
              </Button>
            </form>
          )}
        </div>

        {!missing && categories.length > 0 && (
          <div
            role="group"
            aria-label={t("news.filter_label", "Filter by category")}
            className="mt-6 flex flex-wrap gap-2"
          >
            {[null, ...categories].map((category) => {
              const active = query.category === category;
              return (
                <button
                  key={category ?? "all"}
                  type="button"
                  aria-pressed={active}
                  onClick={() => apply({ ...query, category, page: 1 })}
                  className={cn(
                    "rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    active
                      ? "border-primary bg-primary text-primary-foreground"
                      : "border-border bg-card text-foreground hover:bg-muted",
                  )}
                >
                  {category === null
                    ? t("news.all", "All")
                    : t(`news.category_${category}`, category)}
                </button>
              );
            })}
          </div>
        )}

        <div className="mt-8" aria-live="polite" aria-busy={news.isFetching}>
          {missing ? (
            <NewsState
              icon={Newspaper}
              title={t("news.unavailable_title", "No news here")}
              description={t(
                "news.unavailable_desc",
                "This organisation does not publish news on this page.",
              )}
              action={
                <Button asChild variant="outline">
                  <a href={links.home}>
                    {t("news.back_home", "Back to the organisation's page")}
                  </a>
                </Button>
              }
            />
          ) : (news.isLoading || host.tenantContextLoading) && !news.data ? (
            <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
              {Array.from({ length: 6 }).map((_, i) => (
                <Skeleton key={i} className="h-44 rounded-xl" />
              ))}
            </div>
          ) : news.isError && !news.data ? (
            <NewsState
              icon={Newspaper}
              title={t("news.error_title", "News could not be loaded")}
              description={t(
                "news.error_desc",
                "Check your connection and try again.",
              )}
              action={
                <Button variant="outline" onClick={() => news.refetch()}>
                  {t("news.retry", "Try again")}
                </Button>
              }
            />
          ) : items.length === 0 ? (
            <NewsState
              icon={filtered ? Search : Newspaper}
              title={
                filtered
                  ? t("news.no_results_title", "No stories match")
                  : t("news.empty_title", "No news yet")
              }
              description={
                filtered
                  ? t(
                      "news.no_results_desc",
                      "Try other words or another category.",
                    )
                  : t(
                      "news.empty_desc",
                      "News from this organisation appears here once it is published.",
                    )
              }
              action={
                filtered ? (
                  <Button
                    variant="outline"
                    onClick={() => {
                      setDraft("");
                      apply({ q: "", category: null, page: 1 });
                    }}
                  >
                    <X className="mr-2 h-4 w-4" aria-hidden="true" />
                    {t("news.clear_filters", "Clear search and filters")}
                  </Button>
                ) : null
              }
            />
          ) : (
            <>
              {filtered && meta && (
                <p className="mb-4 text-sm text-muted-foreground">
                  {meta.total === 1
                    ? t("news.result_one", "1 story")
                    : t("news.results", ":count stories", {
                        count: meta.total,
                      })}
                </p>
              )}
              {lead && (
                <div className="mb-4">
                  <NewsCard
                    item={lead}
                    href={links.article(lead.public_id)}
                    size="feature"
                  />
                </div>
              )}
              <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                {rest.map((item) => (
                  <NewsCard
                    key={item.public_id}
                    item={item}
                    href={links.article(item.public_id)}
                  />
                ))}
              </div>
              {meta && meta.last_page > 1 && (
                <nav
                  aria-label={t("news.pagination", "News pages")}
                  className="mt-10 flex items-center justify-between gap-4"
                >
                  <Button
                    variant="outline"
                    disabled={meta.current_page <= 1}
                    onClick={() =>
                      apply({ ...query, page: meta.current_page - 1 })
                    }
                  >
                    <ChevronLeft className="mr-1 h-4 w-4" aria-hidden="true" />
                    {t("news.previous", "Previous")}
                  </Button>
                  <span className="text-sm tabular-nums text-muted-foreground">
                    {t("news.page_of", "Page :current of :last", {
                      current: meta.current_page,
                      last: meta.last_page,
                    })}
                  </span>
                  <Button
                    variant="outline"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() =>
                      apply({ ...query, page: meta.current_page + 1 })
                    }
                  >
                    {t("news.next", "Next")}
                    <ChevronRight className="ml-1 h-4 w-4" aria-hidden="true" />
                  </Button>
                </nav>
              )}
            </>
          )}
        </div>
      </div>
    </PortalChrome>
  );
}
