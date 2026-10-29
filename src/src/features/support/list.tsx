"use client";

import type { ReactNode } from "react";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { cn } from "@/lib/utils";
import { ErrorState } from "@/components/shared/error-state";
import { Skeleton } from "@/components/ui/skeleton";
import type { SupportPage } from "@/features/support/api";
import {
  SupportHandlerLabel,
  SupportStatusBadge,
} from "@/features/support/components";

/** The status filter and request list the help page and the console share. */
export function StatusFilter({
  filters,
  value,
  onChange,
}: {
  filters: Array<{ value: string; label: string; count: number | undefined }>;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <div
      role="radiogroup"
      className="inline-flex flex-wrap rounded-lg border bg-muted/40 p-0.5"
    >
      {filters.map((f) => (
        <button
          key={f.value}
          type="button"
          role="radio"
          aria-checked={value === f.value}
          onClick={() => onChange(f.value)}
          className={cn(
            "rounded-md px-2.5 py-1 text-xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            value === f.value
              ? "bg-background text-foreground shadow-xs"
              : "text-muted-foreground hover:text-foreground",
          )}
        >
          {f.label}
          {f.count !== undefined && f.count > 0 && (
            <span className="ml-1 tabular-nums opacity-70">{f.count}</span>
          )}
        </button>
      ))}
    </div>
  );
}

export function RequestList({
  query,
  onOpen,
  empty,
  showRequester = false,
  showOrganisation = false,
}: {
  query: {
    data: SupportPage | undefined;
    isLoading: boolean;
    isError: boolean;
    refetch: () => unknown;
  };
  onOpen: (id: string) => void;
  empty: ReactNode;
  showRequester?: boolean;
  showOrganisation?: boolean;
}) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();

  if (query.isLoading) return <Skeleton className="h-32" />;
  if (query.isError) return <ErrorState onRetry={() => query.refetch()} />;
  const rows = query.data?.data ?? [];
  if (rows.length === 0) return <>{empty}</>;

  return (
    <ul className="divide-y">
      {rows.map((r) => (
        <li key={r.public_id}>
          <button
            type="button"
            onClick={() => onOpen(r.public_id)}
            className="flex w-full items-start justify-between gap-3 py-3 text-left hover:bg-muted/40"
          >
            <span className="min-w-0">
              <span className="block truncate text-sm font-medium">
                {r.body}
              </span>
              <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                {showOrganisation && r.organisation && (
                  <>
                    <span className="font-medium text-foreground">
                      {r.organisation.name}
                    </span>
                    <span>·</span>
                  </>
                )}
                {t(`support.category.${r.category}`, r.category)}
                {showRequester && r.requester && (
                  <>
                    <span>·</span>
                    {r.requester.name}
                  </>
                )}
                {r.updated_at && (
                  <>
                    <span>·</span>
                    {timeAgo(r.updated_at)}
                  </>
                )}
                {!showOrganisation && (
                  <>
                    <span>·</span>
                    <SupportHandlerLabel handler={r.handled_by} />
                  </>
                )}
              </span>
            </span>
            <SupportStatusBadge status={r.status} />
          </button>
        </li>
      ))}
    </ul>
  );
}
