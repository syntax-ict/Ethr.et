"use client";

import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { RoleGate } from "@/components/shared/role-gate";
import { PossibleDuplicatesList } from "@/features/employees/components/possible-duplicates-list";
import { useT } from "@/lib/i18n/useT";

/**
 * Employees who may be one person recorded twice.
 *
 * Read-only: it lists, and links to the profiles where a person decides.
 * Joining two records into one is not something this page, or ETHR, does
 * yet (One employee identity, PR 11).
 */
export default function PossibleDuplicatesPage() {
  const { t } = useT();

  return (
    <RoleGate anyPermission={["manageEmployees"]}>
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/employees">
              <ArrowLeft className="mr-2 h-4 w-4" />
              {t("employees_import_page.back_to_employees")}
            </Link>
          </Button>
        </div>

        {/* The title bar already names the page (route-meta). */}
        <div>
          <p className="max-w-3xl text-sm text-muted-foreground">
            {t(
              "duplicates.description",
              "Current employees who share something a person has only one of. They may be one person recorded twice, or two people with a detail entered wrongly. Open each profile to check. Nothing is changed here.",
            )}
          </p>
        </div>

        <PossibleDuplicatesList />
      </div>
    </RoleGate>
  );
}
