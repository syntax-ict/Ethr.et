"use client";

import { useEffect } from "react";
import axios from "axios";
import { ArrowLeft, ChevronRight, FileQuestion, Newspaper } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { useRouteId } from "@/lib/hooks/useRouteId";
import { useLandingAnnouncement } from "@/features/auth/landing-feed";
import {
  PortalChrome,
  portalLinks,
} from "@/features/auth/components/portal-chrome";
import { ArticleView } from "./article-view";
import { NewsCard } from "./news-card";
import { NewsState } from "./news-state";

/**
 * One story's page (`/news/{id}`): the story in full, then up to three
 * related ones, inside the organisation's own header and footer. The id
 * arrives in the address (one shell serves every article, see
 * `@/lib/static-export`). Only a story the organisation published for the
 * public exists here; anything else, a draft included, is the same "not
 * found" the server answers.
 */
export function NewsArticle({ routeId }: { routeId: string }) {
  const { t } = useT();
  const host = useAuthHostContext();
  const id = useRouteId(routeId);
  const article = useLandingAnnouncement(host.tenantRequest, id);
  const links = portalLinks(host.tenantLanding, host.carryOrganisation, false);

  const data = article.data;
  useEffect(() => {
    if (!data?.title) return;
    const previous = document.title;
    document.title = host.tenantName
      ? `${data.title} · ${host.tenantName}`
      : data.title;
    return () => {
      document.title = previous;
    };
  }, [data?.title, host.tenantName]);

  const notFound =
    (!host.tenantContextLoading && host.context !== "tenant") ||
    (axios.isAxiosError(article.error) &&
      article.error.response?.status === 404);
  const newsHref = links.news ?? links.home;

  return (
    <PortalChrome
      name={host.tenantName}
      brand={host.tenantBrand}
      landing={host.tenantLanding}
      links={links}
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
            <li>
              <a
                href={newsHref}
                className="rounded-sm hover:text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {t("news.title", "News")}
              </a>
            </li>
          </ol>
        </nav>

        <div className="mt-6">
          {notFound ? (
            <NewsState
              icon={FileQuestion}
              title={t("news.not_found_title", "This story is not available")}
              description={t(
                "news.not_found_desc",
                "It may have been unpublished, or the link is not complete.",
              )}
              action={
                <Button asChild variant="outline">
                  <a href={newsHref}>{t("news.all_news", "See all news")}</a>
                </Button>
              }
            />
          ) : article.isError ? (
            <NewsState
              icon={Newspaper}
              title={t("news.error_title", "News could not be loaded")}
              description={t(
                "news.error_desc",
                "Check your connection and try again.",
              )}
              action={
                <Button variant="outline" onClick={() => article.refetch()}>
                  {t("news.retry", "Try again")}
                </Button>
              }
            />
          ) : !data ? (
            <div className="max-w-prose space-y-4" aria-busy="true">
              <Skeleton className="h-4 w-40" />
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-11/12" />
              <Skeleton className="h-4 w-4/5" />
            </div>
          ) : (
            <>
              <ArticleView article={data} />
              <div className="mt-10">
                <Button asChild variant="ghost" className="-ml-3">
                  <a href={newsHref}>
                    <ArrowLeft className="mr-2 h-4 w-4" aria-hidden="true" />
                    {t("news.back_to_news", "Back to news")}
                  </a>
                </Button>
              </div>
              {data.related.length > 0 && (
                <section
                  aria-labelledby="related-news"
                  className="mt-12 border-t border-border pt-8"
                >
                  <h2
                    id="related-news"
                    className="mb-4 text-lg font-semibold text-foreground"
                  >
                    {t("news.related", "More news")}
                  </h2>
                  <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                    {data.related.map((item) => (
                      <NewsCard
                        key={item.public_id}
                        item={item}
                        href={links.article(item.public_id)}
                      />
                    ))}
                  </div>
                </section>
              )}
            </>
          )}
        </div>
      </div>
    </PortalChrome>
  );
}
