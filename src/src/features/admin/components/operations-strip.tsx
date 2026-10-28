"use client";

import Link from "next/link";
import { Activity, ArrowRight } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import {
  OPERATIONS_AREAS,
  useAdminOperations,
  type OperationsState,
} from "@/features/admin/operations";
import {
  StateBadge,
  areaLabel,
} from "@/features/admin/components/operations-labels";

const EDGE: Record<OperationsState, string> = {
  ok: "border-success-edge",
  warning: "border-warning-edge",
  critical: "border-destructive",
  unknown: "border-neutral-edge",
};

/**
 * One tile per operations area on the console home, each linking to its
 * panel on /admin/operations. The home page answers "is anything wrong?";
 * the operations page answers "what, and since when?".
 */
export function OperationsStrip() {
  const { t } = useT();
  const query = useAdminOperations();

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <Activity className="h-4 w-4" />
          {t("operations_page.strip_title", "Operations")}
          {query.data && <StateBadge state={query.data.status} t={t} />}
        </CardTitle>
        <Button variant="ghost" size="sm" asChild>
          <Link href="/admin/operations">
            {t("operations_page.open", "Open operations")}
            <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
          </Link>
        </Button>
      </CardHeader>
      <CardContent>
        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-16 w-full" />}
          isEmpty={() => false}
        >
          {(status) => (
            <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-5">
              {OPERATIONS_AREAS.map((area) => (
                <Link
                  key={area}
                  href={`/admin/operations#${area}`}
                  className={cn(
                    "flex items-center justify-between gap-2 rounded-lg border border-l-4 px-3 py-2 text-sm transition-colors hover:bg-muted/50",
                    EDGE[status[area].state],
                  )}
                >
                  <span className="truncate font-medium text-foreground">
                    {areaLabel(t, area)}
                  </span>
                  <StateBadge state={status[area].state} t={t} />
                </Link>
              ))}
            </div>
          )}
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}
