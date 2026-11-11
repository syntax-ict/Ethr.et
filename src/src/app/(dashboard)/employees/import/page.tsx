"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { RoleGate } from "@/components/shared/role-gate";
import { ImportColumnGuide } from "@/features/employees/components/import-column-guide";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import { useT } from "@/lib/i18n/useT";

/**
 * Bringing employees in from a file.
 *
 * The same staged review the guided setup and a device's roster use: the list
 * is read, each person is matched to the employees already here, a reviewer
 * decides line by line, and only then is anyone added. This page had a
 * pipeline of its own (upload, preview, commit) with no review, where a
 * person already in ETHR was counted and skipped and nothing they brought was
 * kept (One employee identity, PR 6).
 */
export default function EmployeeImportPage() {
  const { t } = useT();
  const router = useRouter();

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

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="xl:col-span-2">
            <MigrationStep
              sources="list"
              title={t(
                "employees_import_page.title",
                "Import employees from a file",
              )}
              description={t(
                "employees_import_page.description",
                "Choose a CSV file or paste the rows. Everyone is matched to the employees already in ETHR and shown to you before anyone is added, so nobody is added twice.",
              )}
              onCommitted={() => router.push("/employees")}
            />
          </div>
          <ImportColumnGuide />
        </div>
      </div>
    </RoleGate>
  );
}
