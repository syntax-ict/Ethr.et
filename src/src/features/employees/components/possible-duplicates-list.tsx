"use client";

import Link from "next/link";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { EmptyState } from "@/components/shared/empty-state";
import { StatusBadge } from "@/components/shared/status-badge";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import {
  type CreatedVia,
  type DuplicateReason,
  usePossibleDuplicates,
} from "../identities";

/**
 * Employees who may be one person recorded twice (One employee identity,
 * PR 11).
 *
 * Every way an employee is made asks first whether the person is already
 * here; records older than that were never compared. Each group is two or
 * more current employees who share something a person has only one of. The
 * list changes nothing: whether they are one person, and which record to
 * keep, is for someone who knows them, on the profiles this links to.
 */
export function PossibleDuplicatesList() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const query = usePossibleDuplicates();

  const reason: Record<DuplicateReason, string> = {
    national_id: t("duplicates.reason_national_id", "Same national ID"),
    email: t("duplicates.reason_email", "Same email"),
    phone: t("duplicates.reason_phone", "Same phone number"),
    badge_number: t("duplicates.reason_badge", "Same badge number"),
    name_and_birth_date: t(
      "duplicates.reason_name_birth",
      "Same name and date of birth",
    ),
  };

  const via: Record<CreatedVia, string> = {
    manual: t("identities.via_manual", "Added by hand"),
    api: t("identities.via_api", "Added through the API"),
    import: t("identities.via_import", "Brought in from a file"),
    migration: t("identities.via_migration", "Brought in from a list"),
    device: t("identities.via_device", "Brought in from an attendance device"),
    recruitment: t("identities.via_recruitment", "Hired from a vacancy"),
    scim: t("identities.via_scim", "Added by the directory (SCIM)"),
    sso: t("identities.via_sso", "Added at first sign-in (SSO)"),
  };

  return (
    <QueryBoundary
      query={query}
      isEmpty={(data) => data.groups.length === 0}
      empty={
        <EmptyState
          title={t("duplicates.none_title", "No possible duplicates")}
          description={t(
            "duplicates.none_desc",
            "No two current employees share a national ID, an email, a phone number, a badge number, or a name with a date of birth.",
          )}
        />
      }
    >
      {(data) => (
        <div className="space-y-4">
          {data.truncated && (
            <Alert variant="warning">
              <AlertDescription>
                {t(
                  "duplicates.truncated",
                  "There are more than these. The likeliest are shown first; the rest appear as these are dealt with.",
                )}
              </AlertDescription>
            </Alert>
          )}

          <ul className="space-y-3">
            {data.groups.map((group) => (
              <li key={group.employees.map((e) => e.public_id).join(":")}>
                <Card>
                  <CardContent className="space-y-3 p-4">
                    <div className="flex flex-wrap gap-2">
                      {group.reasons.map((r) => (
                        <Badge key={r} variant="outline">
                          {reason[r] ?? r}
                        </Badge>
                      ))}
                    </div>
                    <ul className="divide-y divide-border rounded-md border">
                      {group.employees.map((person) => (
                        <li
                          key={person.public_id}
                          className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                          <div className="min-w-0 text-sm">
                            <Link
                              href={`/employees/${person.public_id}`}
                              className="font-medium text-primary underline-offset-2 hover:underline"
                            >
                              {person.name}
                            </Link>
                            <p className="text-xs text-muted-foreground">
                              <span className="font-mono">
                                {person.employee_code}
                              </span>
                              {person.department
                                ? ` · ${person.department}`
                                : ""}
                              {person.created_via
                                ? ` · ${via[person.created_via] ?? person.created_via}`
                                : ""}
                              {person.created_at
                                ? ` · ${t("duplicates.added_on", "added :date", { date: formatDate(person.created_at) })}`
                                : ""}
                            </p>
                          </div>
                          <StatusBadge status={person.status} />
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              </li>
            ))}
          </ul>
        </div>
      )}
    </QueryBoundary>
  );
}
