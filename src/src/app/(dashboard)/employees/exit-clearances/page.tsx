"use client";

import Link from "next/link";
import { ClipboardCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { EmptyState } from "@/components/shared/empty-state";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useExitClearances,
  type ExitClearance,
} from "@/features/offboarding/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import { useT } from "@/lib/i18n/useT";

/**
 * Exit clearances the reader has a part in: HR sees the leavers it can
 * reach, finance sees all, a supervisor sees their own reports.
 */
export default function ExitClearancesPage() {
  const { t } = useT();
  const filters = useQueryFilters(
    { status: "open" },
    { status: ["open", "complete"] },
  );
  const status = filters.values.status === "complete" ? "complete" : "open";
  const query = useExitClearances(status);

  return (
    <RoleGate
      anyPermission={["transitionEmployees", "manageLoans", "viewTeam"]}
    >
      <Tabs value={status} onValueChange={(v) => filters.set({ status: v })}>
        <TabsList>
          <TabsTrigger value="open">
            {t("exit_clearance.status_open", "Open")}
          </TabsTrigger>
          <TabsTrigger value="complete">
            {t("exit_clearance.status_complete", "Complete")}
          </TabsTrigger>
        </TabsList>
        <TabsContent value={status} className="mt-6">
          <Card>
            <CardContent className="p-0">
              <QueryBoundary
                query={query}
                isEmpty={(page) => page.data.length === 0}
                empty={
                  <EmptyState
                    icon={ClipboardCheck}
                    title={
                      status === "open"
                        ? t(
                            "exit_clearance.list_empty_open",
                            "No one is leaving",
                          )
                        : t(
                            "exit_clearance.list_empty_complete",
                            "No completed exit clearances",
                          )
                    }
                  />
                }
              >
                {(page) => (
                  <ul className="divide-y">
                    {page.data.map((c) => (
                      <ClearanceRow key={c.public_id} clearance={c} />
                    ))}
                  </ul>
                )}
              </QueryBoundary>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </RoleGate>
  );
}

function ClearanceRow({ clearance }: { clearance: ExitClearance }) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const employee = clearance.employee;

  return (
    <li className="flex flex-wrap items-center gap-3 p-4">
      <div className="min-w-0 flex-1">
        {employee ? (
          <Link
            href={`/employees/${employee.public_id}?tab=exit`}
            className="text-sm font-medium hover:underline"
          >
            {employee.name}
          </Link>
        ) : (
          <span className="text-sm font-medium">
            {t("common.unknown", "Unknown")}
          </span>
        )}
        <p className="text-xs text-muted-foreground">
          {exitStatusLabel(clearance.exit_status, t)}
          {clearance.leaving_date
            ? ` · ${formatDate(clearance.leaving_date)}`
            : ""}
        </p>
      </div>
      <Badge variant="outline">
        {t("exit_clearance.progress", ":done of :total done", {
          done: clearance.done_count,
          total: clearance.item_count,
        })}
      </Badge>
    </li>
  );
}

function exitStatusLabel(
  status: string,
  t: ReturnType<typeof useT>["t"],
): string {
  switch (status) {
    case "terminated":
      return t("exit_clearance.exit_terminated", "Terminated");
    case "retired":
      return t("exit_clearance.exit_retired", "Retired");
    default:
      return t("exit_clearance.exit_resigned", "Resigned");
  }
}
