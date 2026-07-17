"use client";

import { useState } from "react";
import Link from "next/link";
import { Building2, Users } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
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
import { RoleGate } from "@/components/shared/role-gate";
import { useAdminTenants } from "@/features/admin/api";
import { useT } from "@/lib/i18n/useT";

export default function AdminTenantsPage() {
  const { t } = useT();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<string>("all");
  const [page, setPage] = useState(1);

  const { data, isLoading } = useAdminTenants({
    search: search || undefined,
    status: status === "all" ? undefined : status,
    page,
    per_page: 25,
  });

  const tenants = data?.data ?? [];

  return (
    <RoleGate allowedRoles={["super_admin"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("admin_tenants_page.title")}
          description={t("admin_tenants_page.description")}
        />

        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="w-full max-w-sm">
            <SearchInput
              value={search}
              onChange={(v) => {
                setSearch(v);
                setPage(1);
              }}
              placeholder={t("admin_tenants_page.search_placeholder")}
            />
          </div>
          <Select
            value={status}
            onValueChange={(v) => {
              setStatus(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="w-full sm:w-48">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("admin_tenants_page.all_statuses")}</SelectItem>
              <SelectItem value="trial">{t("admin_console_page.trial")}</SelectItem>
              <SelectItem value="active">{t("webhooks_page.active")}</SelectItem>
              <SelectItem value="suspended">{t("admin_console_page.suspended")}</SelectItem>
              <SelectItem value="cancelled">{t("admin_console_page.cancelled")}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-16 w-full" />
            ))}
          </div>
        ) : tenants.length === 0 ? (
          <EmptyState
            icon={Building2}
            title={t("admin_tenants_page.no_tenants")}
            description={
              search
                ? t("admin_tenants_page.try_different_search")
                : t("admin_tenants_page.no_tenants_match")
            }
          />
        ) : (
          <>
            <Card>
              <CardContent className="p-0">
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b bg-muted/50">
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                          {t("admin_tenants_page.tenant")}
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground sm:table-cell">
                          {t("admin_tenants_page.subdomain")}
                        </th>
                        <th className="hidden px-4 py-3 text-right text-xs font-medium uppercase text-muted-foreground md:table-cell">
                          {t("attendance.employee")}
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground lg:table-cell">
                          {t("admin_tenants_page.trial_ends")}
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground lg:table-cell">
                          {t("attendance.kiosks_page.created")}
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                          {t("common.status")}
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {tenants.map((tenant) => (
                        <tr
                          key={tenant.public_id}
                          className="border-b last:border-0 hover:bg-muted/30 cursor-pointer"
                        >
                          <td className="px-4 py-3">
                            <Link
                              href={`/admin/tenants/${tenant.public_id}`}
                              className="text-sm font-medium text-foreground hover:text-primary hover:underline"
                            >
                              {tenant.name}
                            </Link>
                            {tenant.type && (
                              <p className="text-xs text-muted-foreground capitalize">
                                {tenant.type}
                              </p>
                            )}
                          </td>
                          <td className="hidden px-4 py-3 text-sm font-mono text-muted-foreground sm:table-cell">
                            {tenant.subdomain}.ethr.et
                          </td>
                          <td className="hidden px-4 py-3 text-right md:table-cell">
                            <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                              <Users className="h-3 w-3" /> {tenant.employee_count}
                            </span>
                          </td>
                          <td className="hidden px-4 py-3 text-sm text-muted-foreground lg:table-cell">
                            {tenant.trial_ends_at
                              ? new Date(tenant.trial_ends_at).toLocaleDateString()
                              : "—"}
                          </td>
                          <td className="hidden px-4 py-3 text-sm text-muted-foreground lg:table-cell">
                            {new Date(tenant.created_at).toLocaleDateString()}
                          </td>
                          <td className="px-4 py-3">
                            <StatusBadge status={tenant.status} />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>

            {data?.last_page && data.last_page > 1 && (
              <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                  {t("audit_logs_page.showing")} {data.from}–{data.to}{" "}
                  {t("audit_logs_page.of")} {data.total}
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                  >
                    {t("audit_logs_page.previous")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= data.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    {t("audit_logs_page.next")}
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
