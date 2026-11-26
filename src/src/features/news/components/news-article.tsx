"use client";

import axios from "axios";
import { ArrowLeft, FileQuestion } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useRouteId } from "@/lib/hooks/useRouteId";
import { useLandingAnnouncement } from "@/features/auth/landing-feed";
import { PortalChrome } from "@/features/auth/components/portal-chrome";
import {
  PortalBreadcrumb,
  usePortalPage,
  usePortalTitle,
} from "@/features/auth/components/portal-page";
import { ArticleView } from "./article-view";
import { NewsCard } from "./news-card";
import { NewsBoundary, NewsState } from "./news-state";

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
  const id = useRouteId(routeId);
  const page = usePortalPage();
  const { host, links, paused } = page;
  const article = useLandingAnnouncement(host.tenantRequest, id);
  usePortalTitle(article.data?.title, host.tenantName);

  const notFound =
    (!host.tenantContextLoading && host.context !== "tenant") ||
    (axios.isAxiosError(article.error) &&
      article.error.response?.status === 404);
  const newsHref = links.news ?? links.home;

  const missing = (
    <NewsState
      icon={FileQuestion}
      title={
        paused
          ? t("news.paused_title", "News is temporarily unavailable")
          : t("news.not_found_title", "This story is not available")
      }
      description={
        paused
          ? t(
              "news.paused_desc",
              "This page is switched off for a while. Please check back later.",
            )
          : t(
              "news.not_found_desc",
              "It may have been unpublished, or the link is not complete.",
            )
      }
      action={
        <Button asChild variant="outline">
          <a href={newsHref}>{t("news.all_news", "See all news")}</a>
        </Button>
      }
    />
  );

  return (
    <PortalChrome {...page.chrome}>
      <div className="py-10 @lg:py-14">
        <PortalBreadcrumb
          page={page}
          trail={[{ label: t("news.title", "News"), href: newsHref }]}
        />

        <div className="mt-6">
          <NewsBoundary
            query={article}
            // See NewsListing: with no organisation behind the address the
            // query never starts.
            loading={
              notFound ? (
                missing
              ) : (
                <div className="max-w-prose space-y-4" aria-busy="true">
                  <Skeleton className="h-4 w-40" />
                  <Skeleton className="h-9 w-full" />
                  <Skeleton className="h-4 w-full" />
                  <Skeleton className="h-4 w-11/12" />
                  <Skeleton className="h-4 w-4/5" />
                </div>
              )
            }
            error={notFound ? missing : undefined}
            isEmpty={() => false}
          >
            {(data) => (
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
                    <ul className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                      {data.related.map((item) => (
                        <li key={item.public_id} className="min-w-0">
                          <NewsCard
                            item={item}
                            href={links.article(item.public_id)}
                            headingLevel={3}
                          />
                        </li>
                      ))}
                    </ul>
                  </section>
                )}
              </>
            )}
          </NewsBoundary>
        </div>
      </div>
    </PortalChrome>
  );
}
