"use client";

import { Suspense } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { RoleGate } from "@/components/shared/role-gate";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import { useT } from "@/lib/i18n/useT";

/**
 * The workforce migration outside onboarding: people enrolled on an
 * attendance device, matched to employees, brought in, and then given a way
 * to sign in. The same component the onboarding wizard uses, opened from a
 * device with `?device=<public id>`.
 */
function FromDevice() {
  const params = useSearchParams();

  return <MigrationStep initialDeviceId={params.get("device") ?? undefined} />;
}

export default function EmployeesFromDevicePage() {
  const { t } = useT();

  return (
    <RoleGate anyPermission={["manageEmployees"]}>
      <div className="space-y-6">
        <Button variant="ghost" size="sm" asChild>
          <Link href="/devices">
            <ArrowLeft className="mr-2 h-4 w-4" />
            {t("migration_logins.back_to_devices", "Devices")}
          </Link>
        </Button>
        <Suspense fallback={<Skeleton className="h-64" />}>
          <FromDevice />
        </Suspense>
      </div>
    </RoleGate>
  );
}
