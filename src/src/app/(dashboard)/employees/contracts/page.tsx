"use client";

import Link from "next/link";
import { CalendarClock } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { EmptyState } from "@/components/shared/empty-state";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { RoleGate } from "@/components/shared/role-gate";
import {
  type EmployeeContract,
  useExpiringContracts,
} from "@/features/employees/api";
import { contractTypeLabel } from "@/features/employees/components/contracts-tab";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import { useT } from "@/lib/i18n/useT";

const WINDOWS = ["30", "60", "90"] as const;

/**
 * Contracts that end soon, and ones past their end date that nobody renewed
 * or ended, soonest first. Renewing and ending happen on the person's own
 * Contracts tab, where the rules for both already live; this page only says
 * whose contract needs a decision.
 */
export default function ContractsEndingPage() {
  const { t } = useT();
  const filters = useQueryFilters({ days: "30" }, { days: [...WINDOWS] });
  const days = Number(filters.values.days);
  const query = useExpiringContracts(days);

  return (
    <RoleGate anyPermission={["manageEmployees"]}>
      <Tabs
        value={String(days)}
        onValueChange={(v) => filters.set({ days: v })}
      >
        <TabsList>
          {WINDOWS.map((w) => (
            <TabsTrigger key={w} value={w}>
              {t("contracts_ending.window", "Next :days days", { days: w })}
            </TabsTrigger>
          ))}
        </TabsList>
        <TabsContent value={String(days)} className="mt-6">
          <Card>
            <CardContent className="p-0">
              <QueryBoundary
                query={query}
                isEmpty={(page) => page.data.length === 0}
                empty={
                  <EmptyState
                    icon={CalendarClock}
                    title={t(
                      "contracts_ending.empty",
                      "No contract ends in the next :days days",
                      { days },
                    )}
                    description={t(
                      "contracts_ending.empty_hint",
                      "Permanent contracts have no end date, so they never appear here.",
                    )}
                  />
                }
              >
                {(page) => (
                  <>
                    <ul className="divide-y">
                      {page.data.map((c) => (
                        <ContractRow key={c.public_id} contract={c} />
                      ))}
                    </ul>
                    {page.meta.total > page.data.length && (
                      <p className="border-t p-4 text-xs text-muted-foreground">
                        {t(
                          "contracts_ending.more",
                          "Showing the first :shown of :total, soonest first.",
                          { shown: page.data.length, total: page.meta.total },
                        )}
                      </p>
                    )}
                  </>
                )}
              </QueryBoundary>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </RoleGate>
  );
}

function ContractRow({ contract }: { contract: EmployeeContract }) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const left = contract.days_until_expiry;

  return (
    <li className="flex flex-wrap items-center gap-3 p-4">
      <div className="min-w-0 flex-1">
        {contract.employee_public_id ? (
          <Link
            href={`/employees/${contract.employee_public_id}?tab=contracts`}
            className="text-sm font-medium hover:underline"
          >
            {contract.employee_name}
          </Link>
        ) : (
          <span className="text-sm font-medium">
            {t("common.unknown", "Unknown")}
          </span>
        )}
        <p className="text-xs text-muted-foreground">
          {contractTypeLabel(contract.contract_type, t)}
          {contract.end_date
            ? ` · ${t("contracts_ending.ends_on", "Ends :date", {
                date: formatDate(contract.end_date),
              })}`
            : ""}
        </p>
      </div>
      {left !== null && (
        <Badge variant={left < 0 ? "warning" : "outline"}>
          {left < 0
            ? Math.abs(left) === 1
              ? t(
                  "contracts_ending.overdue_one",
                  "Ended 1 day ago, not renewed or ended",
                )
              : t(
                  "contracts_ending.overdue",
                  "Ended :count days ago, not renewed or ended",
                  { count: Math.abs(left) },
                )
            : left === 0
              ? t("contracts_ending.today", "Ends today")
              : left === 1
                ? t("contracts_ending.in_one", "Ends tomorrow")
                : t("contracts_ending.in_days", "Ends in :count days", {
                    count: left,
                  })}
        </Badge>
      )}
    </li>
  );
}
