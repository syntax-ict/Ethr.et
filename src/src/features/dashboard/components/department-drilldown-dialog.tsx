"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Users, Wallet } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { StatusBadge } from "@/components/shared/status-badge";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";

interface DepartmentDetail {
  public_id: string;
  name: string;
  headcount: number;
  avg_salary_cents: number;
  gender_breakdown: Record<string, number>;
  employees: Array<{ public_id: string; name: string; status: string }>;
}

/**
 * The real interactive drill-down: click a department bar on any chart above
 * and this opens with that department's headcount, average salary, gender
 * split, and roster — reusing `GET /analytics/departments/{id}`, which
 * already existed with no frontend consumer.
 */
export function DepartmentDrillDownDialog({
  departmentPublicId,
  onOpenChange,
}: {
  departmentPublicId: string | null;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useT();
  const query = useQuery<DepartmentDetail>({
    queryKey: ["analytics", "departments", departmentPublicId],
    enabled: departmentPublicId !== null,
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/analytics/departments/${departmentPublicId}`,
      );
      return data;
    },
  });

  return (
    <Dialog open={departmentPublicId !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {query.data?.name ??
              t("executive_dashboard.department_detail", "Department")}
          </DialogTitle>
        </DialogHeader>

        <QueryBoundary
          query={query}
          loading={
            <div className="space-y-3">
              <Skeleton className="h-16 w-full" />
              <Skeleton className="h-40 w-full" />
            </div>
          }
        >
          {(data) => (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div className="flex items-center gap-2 rounded-lg border p-3">
                  <Users className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-xs text-muted-foreground">
                      {t("executive_dashboard.headcount", "Headcount")}
                    </p>
                    <p className="font-mono text-lg font-semibold tabular-nums">
                      {data.headcount}
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-2 rounded-lg border p-3">
                  <Wallet className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-xs text-muted-foreground">
                      {t("executive_dashboard.avg_salary", "Avg. salary")}
                    </p>
                    <CurrencyDisplay
                      cents={data.avg_salary_cents}
                      className="text-lg font-semibold"
                    />
                  </div>
                </div>
              </div>

              {Object.keys(data.gender_breakdown).length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                  {Object.entries(data.gender_breakdown).map(([g, n]) => (
                    <Badge key={g} variant="outline" className="capitalize">
                      {g}: {n}
                    </Badge>
                  ))}
                </div>
              )}

              <div className="max-h-64 space-y-1 overflow-y-auto">
                {data.employees.map((e) => (
                  <Link
                    key={e.public_id}
                    href={`/employees/${e.public_id}`}
                    className="flex items-center justify-between rounded-md px-2 py-1.5 text-sm hover:bg-muted/50"
                  >
                    <span className="truncate">{e.name}</span>
                    <StatusBadge status={e.status} />
                  </Link>
                ))}
              </div>
            </div>
          )}
        </QueryBoundary>
      </DialogContent>
    </Dialog>
  );
}
