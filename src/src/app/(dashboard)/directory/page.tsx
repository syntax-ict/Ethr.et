"use client";

import { useState } from "react";
import { Users, Phone, Mail } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { PageHeader } from "@/components/shared/page-header";
import { SearchInput } from "@/components/shared/search-input";
import { EmptyState } from "@/components/shared/empty-state";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

interface DirectoryEntry {
  public_id: string;
  name: string;
  phone: string | null;
  email: string | null;
  department: string | null;
  position: string | null;
  branch: string | null;
}

export default function DirectoryPage() {
  const { t } = useT();
  const [search, setSearch] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["directory", search],
    queryFn: async () => {
      const { data } = await apiClient.get("/directory", {
        params: { search: search || undefined, per_page: 50 },
      });
      return data;
    },
  });

  const entries: DirectoryEntry[] = data?.data ?? [];

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
          onChange={setSearch}
          placeholder={t(
            "directory.search_placeholder",
            "Search by name, email, or phone...",
          )}
        />
      </div>

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
      ) : entries.length === 0 ? (
        <EmptyState
          icon={Users}
          title={t("directory.empty_title", "No employees found")}
          description={
            search
              ? t("directory.try_different", "Try a different search")
              : t("directory.empty_desc", "No employees in the directory yet")
          }
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {entries.map((e) => {
            const initials = e.name
              .split(" ")
              .map((n) => n[0])
              .join("")
              .slice(0, 2)
              .toUpperCase();
            return (
              <Card
                key={e.public_id}
                className="hover:shadow-md transition-shadow"
              >
                <CardContent className="p-4">
                  <div className="flex items-start gap-3">
                    <Avatar className="h-10 w-10">
                      <AvatarFallback className="text-sm font-medium">
                        {initials}
                      </AvatarFallback>
                    </Avatar>
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
    </div>
  );
}
