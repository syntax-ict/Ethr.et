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

export default function AdminTenantsPage() {
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
          title="Tenant Management"
          description="View and manage all tenant organizations on the platform"
        />

        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="w-full max-w-sm">
            <SearchInput
              value={search}
              onChange={(v) => {
                setSearch(v);
                setPage(1);
              }}
              placeholder="Search by name or subdomain..."
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
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="trial">Trial</SelectItem>
              <SelectItem value="active">Active</SelectItem>
              <SelectItem value="suspended">Suspended</SelectItem>
              <SelectItem value="cancelled">Cancelled</SelectItem>
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
            title="No tenants found"
            description={
              search
                ? "Try a different search term"
                : "No tenants match the current filters"
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
                          Tenant
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground sm:table-cell">
                          Subdomain
                        </th>
                        <th className="hidden px-4 py-3 text-right text-xs font-medium uppercase text-muted-foreground md:table-cell">
                          Employees
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground lg:table-cell">
                          Trial Ends
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground lg:table-cell">
                          Created
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                          Status
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {tenants.map((t) => (
                        <tr
                          key={t.public_id}
                          className="border-b last:border-0 hover:bg-muted/30 cursor-pointer"
                        >
                          <td className="px-4 py-3">
                            <Link
                              href={`/admin/tenants/${t.public_id}`}
                              className="text-sm font-medium text-foreground hover:text-primary hover:underline"
                            >
                              {t.name}
                            </Link>
                            {t.type && (
                              <p className="text-xs text-muted-foreground capitalize">
                                {t.type}
                              </p>
                            )}
                          </td>
                          <td className="hidden px-4 py-3 text-sm font-mono text-muted-foreground sm:table-cell">
                            {t.subdomain}.ethr.et
                          </td>
                          <td className="hidden px-4 py-3 text-right md:table-cell">
                            <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                              <Users className="h-3 w-3" /> {t.employee_count}
                            </span>
                          </td>
                          <td className="hidden px-4 py-3 text-sm text-muted-foreground lg:table-cell">
                            {t.trial_ends_at
                              ? new Date(t.trial_ends_at).toLocaleDateString()
                              : "—"}
                          </td>
                          <td className="hidden px-4 py-3 text-sm text-muted-foreground lg:table-cell">
                            {new Date(t.created_at).toLocaleDateString()}
                          </td>
                          <td className="px-4 py-3">
                            <StatusBadge status={t.status} />
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
                  Showing {data.from}–{data.to} of {data.total}
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= data.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    Next
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
