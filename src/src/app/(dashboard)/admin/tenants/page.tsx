"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { AlertTriangle, Building2, RefreshCw, Users } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageHeader } from "@/components/shared/page-header";
import { SearchInput } from "@/components/shared/search-input";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { useAdminTenants } from "@/features/admin/api";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

function trialDaysLeft(trialEndsAt: string | null): number | null {
  if (!trialEndsAt) return null;
  return Math.ceil((new Date(trialEndsAt).getTime() - Date.now()) / 86_400_000);
}

export default function AdminTenantsPage() {
  const { t } = useT();
  const router = useRouter();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<string>("all");
  const [page, setPage] = useState(1);

  const { data, isLoading, isError, refetch } = useAdminTenants({
    search: search || undefined,
    status: status === "all" ? undefined : status,
    page,
    per_page: 25,
  });

  const tenants = data?.data ?? [];
  const total = data?.meta?.total;

  return (
    <RoleGate minRole="super_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("admin_tenants_page.title", "Tenants")}
          description={
            total !== undefined
              ? `${total} ${t("admin_tenants_page.total_tenants", "total tenants")}`
              : t("admin_tenants_page.description", "Manage platform tenants")
          }
        />

        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="w-full max-w-sm">
            <SearchInput
              value={search}
              onChange={(v) => {
                setSearch(v);
                setPage(1);
              }}
              placeholder={t(
                "admin_tenants_page.search_placeholder",
                "Search tenants...",
              )}
            />
          </div>
          <Select
            value={status}
            onValueChange={(v) => {
              setStatus(v);
              setPage(1);
            }}
          >
            {/* The trigger's only content is the selected value, so it has no
                accessible name of its own and announced as an unnamed button —
                the user hears the current value with no idea what it filters. */}
            <SelectTrigger
              className="w-full sm:w-48"
              aria-label={t(
                "admin_tenants_page.filter_by_status",
                "Filter by status",
              )}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">
                {t("admin_tenants_page.all_statuses", "All statuses")}
              </SelectItem>
              <SelectItem value="trial">
                {t("admin_console_page.trial", "Trial")}
              </SelectItem>
              <SelectItem value="active">
                {t("admin_console_page.active_label", "Active")}
              </SelectItem>
              <SelectItem value="suspended">
                {t("admin_console_page.suspended", "Suspended")}
              </SelectItem>
              <SelectItem value="cancelled">
                {t("admin_console_page.cancelled", "Cancelled")}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-16 w-full" />
            ))}
          </div>
        ) : isError ? (
          <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed py-16 text-center">
            <AlertTriangle className="h-8 w-8 text-status-error" />
            <p className="text-sm font-medium text-foreground">
              {t("admin_tenants_page.load_failed", "Couldn't load tenants")}
            </p>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              <RefreshCw className="mr-1.5 h-3 w-3" />
              {t("common.retry", "Try again")}
            </Button>
          </div>
        ) : tenants.length === 0 ? (
          <EmptyState
            icon={Building2}
            title={t("admin_tenants_page.no_tenants", "No tenants found")}
            description={
              search
                ? t(
                    "admin_tenants_page.try_different_search",
                    "Try a different search term",
                  )
                : t(
                    "admin_tenants_page.no_tenants_match",
                    "No tenants match the selected filter",
                  )
            }
          />
        ) : (
          <>
            <Card>
              <CardContent className="p-0">
                <SimpleTable
                  caption={t("admin_tenants_page.title", "Tenants")}
                  headers={[
                    t("admin_tenants_page.tenant", "Tenant"),
                    t("admin_tenants_page.subdomain", "Subdomain"),
                    t("admin_tenants_page.employees", "Employees"),
                    t("admin_tenants_page.trial_ends", "Trial Ends"),
                    t("admin_tenants_page.created", "Created"),
                    t("common.status", "Status"),
                  ]}
                  align={["left", "left", "right", "left", "left", "left"]}
                  colClassName={[
                    "",
                    "hidden sm:table-cell",
                    "hidden md:table-cell",
                    "hidden lg:table-cell",
                    "hidden lg:table-cell",
                    "",
                  ]}
                  rows={tenants.map((tenant) => {
                    const days = trialDaysLeft(tenant.trial_ends_at);
                    const expiringSoon = days !== null && days > 0 && days <= 7;
                    const expired = days !== null && days <= 0;
                    return {
                      key: tenant.public_id,
                      onClick: () =>
                        router.push(`/admin/tenants/${tenant.public_id}`),
                      cells: [
                        <div key="n">
                          <span className="text-sm font-medium text-foreground">
                            {tenant.name}
                          </span>
                          {tenant.type && (
                            <p className="text-xs capitalize text-muted-foreground">
                              {tenant.type}
                            </p>
                          )}
                        </div>,
                        <span
                          key="s"
                          className="font-mono text-muted-foreground"
                        >
                          {tenant.subdomain}.ethr.et
                        </span>,
                        <span
                          key="e"
                          className="inline-flex items-center gap-1 tabular-nums text-muted-foreground"
                        >
                          <Users className="h-3 w-3" /> {tenant.employee_count}
                        </span>,
                        tenant.trial_ends_at ? (
                          <div key="t" className="flex items-center gap-1.5">
                            <span
                              className={cn(
                                "tabular-nums",
                                expiringSoon && "text-status-warning",
                                expired && "text-status-error",
                                !expiringSoon &&
                                  !expired &&
                                  "text-muted-foreground",
                              )}
                            >
                              {new Date(
                                tenant.trial_ends_at,
                              ).toLocaleDateString()}
                            </span>
                            {expiringSoon && (
                              <Badge
                                variant="outline"
                                className="border-status-warning/30 text-[10px] text-status-warning"
                              >
                                {days}d
                              </Badge>
                            )}
                            {expired && (
                              <Badge
                                variant="outline"
                                className="border-status-error/30 text-[10px] text-status-error"
                              >
                                {t("admin_tenants_page.expired", "Expired")}
                              </Badge>
                            )}
                          </div>
                        ) : (
                          <span key="t" className="text-muted-foreground">
                            —
                          </span>
                        ),
                        <span key="c" className="text-muted-foreground">
                          {new Date(tenant.created_at).toLocaleDateString()}
                        </span>,
                        <StatusBadge key="st" status={tenant.status} />,
                      ],
                    };
                  })}
                />
              </CardContent>
            </Card>

            {data?.meta && data.meta.last_page > 1 && (
              <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                  {data.meta.from}–{data.meta.to}{" "}
                  {t("admin_tenants_page.of", "of")} {data.meta.total}
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                  >
                    {t("admin_tenants_page.previous", "Previous")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= data.meta.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    {t("admin_tenants_page.next", "Next")}
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </RoleGate>
  );
}
