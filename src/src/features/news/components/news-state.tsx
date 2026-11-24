"use client";

import type { ReactNode } from "react";
import { Newspaper, type LucideIcon } from "lucide-react";
import type { UseQueryResult } from "@tanstack/react-query";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

/**
 * The news pages' empty, missing and error states: what happened, in plain
 * words, and the one thing to do next. The public skin of `EmptyState` and
 * `ErrorState`, which the signed-in screens use.
 */
export function NewsState({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center rounded-xl border border-dashed border-border px-6 py-14 text-center">
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
        <Icon className="h-6 w-6 text-muted-foreground" aria-hidden="true" />
      </span>
      <h2 className="mt-4 text-base font-semibold text-foreground">{title}</h2>
      <p className="mt-1 max-w-sm text-sm text-muted-foreground">
        {description}
      </p>
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

/**
 * `QueryBoundary` for a news page's data: the four states of Convention 13,
 * with the public skin as the error state unless a caller names its own.
 *
 * A query that has not started (it waits for the organisation to be known,
 * or for the address's id) is still loading, which `QueryBoundary` alone
 * would draw as nothing.
 */
export function NewsBoundary<T>({
  query,
  loading,
  empty,
  error,
  isEmpty,
  children,
}: {
  query: UseQueryResult<T>;
  loading: ReactNode;
  empty?: ReactNode;
  error?: ReactNode;
  isEmpty?: (data: T) => boolean;
  children: (data: T) => ReactNode;
}) {
  const { t } = useT();

  if (query.isPending) return <>{loading}</>;

  return (
    <QueryBoundary
      query={query}
      loading={loading}
      empty={empty}
      isEmpty={isEmpty}
      error={
        error ?? (
          <NewsState
            icon={Newspaper}
            title={t("news.error_title", "News could not be loaded")}
            description={t(
              "news.error_desc",
              "Check your connection and try again.",
            )}
            action={
              <Button variant="outline" onClick={() => query.refetch()}>
                {t("news.retry", "Try again")}
              </Button>
            }
          />
        )
      }
    >
      {children}
    </QueryBoundary>
  );
}
