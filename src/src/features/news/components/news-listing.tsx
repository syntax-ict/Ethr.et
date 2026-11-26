"use client";

import axios from "axios";
import { Newspaper, Search, X } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useLandingNews } from "@/features/auth/landing-feed";
import { PortalChrome } from "@/features/auth/components/portal-chrome";
import {
  PortalBreadcrumb,
  usePortalPage,
  usePortalTitle,
} from "@/features/auth/components/portal-page";
import { NewsCard } from "./news-card";
import { NewsState } from "./news-state";
import { StoryBrowser, useStoryFilters } from "./story-browser";

/**
 * The organisation's news page (`/news`): every story it published for the
 * public, the featured ones first, a page at a time, with a search and a
 * category filter. The same shared page for every organisation, drawn from
 * its own data; the filters live in the address, so a filtered list can be
 * shared and the back button walks back through it.
 *
 * The browsing is `StoryBrowser`'s, shared with the signed-in list. This
 * keeps what only a public page has: the portal frame, and the missing and
 * paused states.
 */
export function NewsListing() {
  const { t } = useT();
  const page = usePortalPage();
  const { host, links, paused } = page;
  usePortalTitle(
    host.tenantName ? t("news.title", "News") : null,
    host.tenantName,
  );
  const filters = useStoryFilters({ push: true });
  const news = useLandingNews(host.tenantRequest, filters.query);

  // No organisation behind this address, or it publishes no news page: the
  // page says so and offers the way home, rather than an empty grid.
  const missing =
    (!host.tenantContextLoading && host.context !== "tenant") ||
    (axios.isAxiosError(news.error) && news.error.response?.status === 404);

  const unavailable = (
    <NewsState
      icon={Newspaper}
      title={
        paused
          ? t("news.paused_title", "News is temporarily unavailable")
          : t("news.unavailable_title", "No news here")
      }
      description={
        paused
          ? t(
              "news.paused_desc",
              "This page is switched off for a while. Please check back later.",
            )
          : t(
              "news.unavailable_desc",
              "This organisation does not publish news on this page.",
            )
      }
      action={
        <Button asChild variant="outline">
          <a href={links.home}>
            {t("news.back_home", "Back to the organisation's page")}
          </a>
        </Button>
      }
    />
  );

  return (
    <PortalChrome
      {...page.chrome}
      links={missing ? { ...links, news: null } : links}
    >
      <div className="py-10 @lg:py-14">
        <PortalBreadcrumb
          page={page}
          trail={[{ label: t("news.title", "News") }]}
        />

        <StoryBrowser
          filters={filters}
          query={news}
          search={!missing}
          category={!missing}
          lead
          header={
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
          }
          renderItem={(item, { lead }) => (
            <NewsCard
              item={item}
              href={links.article(item.public_id)}
              density={lead ? "feature" : "card"}
            />
          )}
          // With no organisation behind the address the query never starts,
          // and "loading" would be forever: say it is not here instead.
          loading={
            missing ? (
              unavailable
            ) : (
              <ul className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                {Array.from({ length: 6 }).map((_, i) => (
                  <li key={i}>
                    <Skeleton className="h-44 rounded-xl" />
                  </li>
                ))}
              </ul>
            )
          }
          error={missing ? unavailable : undefined}
          empty={({ filtered, clear }) => (
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
                  <Button variant="outline" onClick={clear}>
                    <X className="mr-2 h-4 w-4" aria-hidden="true" />
                    {t("news.clear_filters", "Clear search and filters")}
                  </Button>
                ) : null
              }
            />
          )}
        />
      </div>
    </PortalChrome>
  );
}
