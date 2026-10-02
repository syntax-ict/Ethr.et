"use client";

import { useState } from "react";
import { Users, Phone, Mail } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { PageHeader } from "@/components/shared/page-header";
import { SearchInput } from "@/components/shared/search-input";
import { EmptyState } from "@/components/shared/empty-state";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { useDirectory } from "@/features/directory/api";

export default function DirectoryPage() {
  const { t } = useT();
  const [search, setSearch] = useState("");
  // The directory is paginated server-side and the page asked for 50 with no
  // way past them: in a tenant of 51 people the 51st could be found only by
  // already knowing their name.
  const [page, setPage] = useState(1);

  const query = useDirectory({ search, page, per_page: 50 });

  const entries = query.data?.data ?? [];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("directory.title", "Employee Directory")}
        description={t(
          "directory.description",
          "Find contact information for colleagues",
        )}
      />

      <div className="w-full max-w-sm">
        <SearchInput
          value={search}
          onChange={(value) => {
            setSearch(value);
            setPage(1);
          }}
          placeholder={t(
            "directory.search_placeholder",
            "Search by name, email, or phone...",
          )}
        />
      </div>

      <QueryBoundary
        query={query}
        loading={
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-32" />
            ))}
          </div>
        }
        isEmpty={() => entries.length === 0}
        empty={
          <EmptyState
            icon={Users}
            title={t("directory.empty_title", "No employees found")}
            description={
              search
                ? t("directory.try_different", "Try a different search")
                : t("directory.empty_desc", "No employees in the directory yet")
            }
          />
        }
      >
        {() => (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {entries.map((e) => {
              return (
                <Card
                  key={e.public_id}
                  className="hover:shadow-md transition-shadow"
                >
                  <CardContent className="p-4">
                    <div className="flex items-start gap-3">
                      <EmployeeAvatar
                        name={e.name}
                        photoThumbUrl={e.photo_thumb_url}
                        photoUrl={e.photo_url}
                        className="h-10 w-10"
                        fallbackClassName="text-sm font-medium"
                      />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-foreground">
                          {e.name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {e.position ?? e.department ?? ""}
                        </p>
                        {e.department && e.position && (
                          <p className="text-xs text-muted-foreground">
                            {e.department}
                          </p>
                        )}
                        <div className="mt-2 space-y-1">
                          {e.email && (
                            <a
                              href={`mailto:${e.email}`}
                              className="flex items-center gap-1.5 text-xs text-primary hover:underline"
                            >
                              <Mail className="h-3 w-3" /> {e.email}
                            </a>
                          )}
                          {e.phone && (
                            <a
                              href={`tel:${e.phone}`}
                              className="flex items-center gap-1.5 text-xs text-primary hover:underline"
                            >
                              <Phone className="h-3 w-3" /> {e.phone}
                            </a>
                          )}
                        </div>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}
      </QueryBoundary>

      <PaginationControls
        meta={query.data?.meta}
        onPageChange={setPage}
        disabled={query.isFetching}
      />
    </div>
  );
}
