"use client";

import { CalendarClock, Landmark } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { useKindNamer } from "@/features/admin/components/org-kind-name";

interface TenantKindCardProps {
  kind: string | null | undefined;
  needsReview: boolean | null | undefined;
  pending:
    { organization_type: string; effective_from: string } | null | undefined;
}

/**
 * The organisation's kind in law, which decides its statutory payroll rules.
 * Read-only on purpose: a change of kind is the organisation's own decision,
 * made in its Settings, so nothing here edits it.
 */
export function TenantKindCard({
  kind,
  needsReview,
  pending,
}: TenantKindCardProps) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const kindName = useKindNamer();

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Landmark
            className="h-4 w-4 text-muted-foreground"
            aria-hidden="true"
          />
          <CardTitle className="text-base">
            {t("settings.organization_kind", "Kind of organisation in law")}
          </CardTitle>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium text-foreground">
            {kind ? kindName(kind) : "—"}
          </span>
          {needsReview === true && (
            <Badge variant="warning">
              {t(
                "admin_tenant_detail_page.kind_needs_review",
                "Needs confirming",
              )}
            </Badge>
          )}
        </div>
        {pending && (
          <p
            role="status"
            className="flex items-start gap-1.5 text-xs text-muted-foreground"
          >
            <CalendarClock
              className="mt-0.5 h-3.5 w-3.5 shrink-0"
              aria-hidden="true"
            />
            {t(
              "settings.organization_kind_pending",
              "Becomes :kind on :date. Payroll before then keeps the current kind.",
              {
                kind: kindName(pending.organization_type),
                date: formatDate(pending.effective_from),
              },
            )}
          </p>
        )}
        <p className="text-xs text-muted-foreground">
          {t(
            "admin_tenant_detail_page.kind_read_only",
            "The organisation changes this itself, in its Settings.",
          )}
        </p>
      </CardContent>
    </Card>
  );
}
