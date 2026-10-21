"use client";

import { type ReactNode } from "react";
import { type UseQueryResult } from "@tanstack/react-query";
import { Inbox } from "lucide-react";
import { ErrorState } from "@/components/shared/error-state";
import { useT } from "@/lib/i18n/useT";

interface QueryBoundaryProps<T> {
  query: UseQueryResult<T>;
  loading?: ReactNode;
  empty?: ReactNode;
  error?: ReactNode;
  isEmpty?: (data: T) => boolean;
  children: (data: T) => ReactNode;
}

function DefaultSkeleton() {
  return (
    <div className="animate-pulse space-y-3 p-4">
      <div className="h-4 w-3/4 rounded bg-muted" />
      <div className="h-4 w-1/2 rounded bg-muted" />
      <div className="h-4 w-5/6 rounded bg-muted" />
    </div>
  );
}

function DefaultEmpty() {
  const { t } = useT();

  return (
    <div className="flex flex-col items-center justify-center py-12 text-center">
      <Inbox className="h-10 w-10 text-muted-foreground" />
      <p className="mt-3 text-sm font-medium text-muted-foreground">
        {t("common.no_results", "No results found")}
      </p>
    </div>
  );
}

function defaultIsEmpty<T>(data: T): boolean {
  if (data == null) return true;
  if (Array.isArray(data)) return data.length === 0;
  if (typeof data === "object" && "data" in (data as Record<string, unknown>)) {
    const inner = (data as Record<string, unknown>).data;
    return Array.isArray(inner) && inner.length === 0;
  }
  return false;
}

export function QueryBoundary<T>({
  query,
  loading,
  empty,
  error,
  isEmpty = defaultIsEmpty,
  children,
}: QueryBoundaryProps<T>) {
  if (query.isLoading) {
    return <>{loading ?? <DefaultSkeleton />}</>;
  }

  if (query.isError) {
    return <>{error ?? <ErrorState onRetry={() => query.refetch()} />}</>;
  }

  if (query.data !== undefined && isEmpty(query.data)) {
    return <>{empty ?? <DefaultEmpty />}</>;
  }

  if (query.data !== undefined) {
    return <>{children(query.data)}</>;
  }

  return null;
}
