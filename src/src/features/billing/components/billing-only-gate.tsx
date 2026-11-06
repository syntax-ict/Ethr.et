"use client";

import { LogOut } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  useBillingOnly,
  useCurrentTenant,
  useLogout,
} from "@/features/auth/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import BillingPage from "@/app/(dashboard)/billing/page";

/**
 * In place of the dashboard while the organisation is locked out for money
 * (`billing_only` on /auth/me: a trial that has ended, or a suspension with a
 * bill unpaid). The API answers only sign-in and billing then (A-Z review
 * H-09), so the full shell would be a screen of refusals. Whoever manages
 * billing gets the billing page, to see the bill, the bank details and the
 * plans; everyone else is told who can put it right.
 */
export function BillingOnlyGate({ children }: { children: React.ReactNode }) {
  const billingOnly = useBillingOnly();

  if (!billingOnly) return <>{children}</>;

  return <BillingOnlyScreen />;
}

function BillingOnlyScreen() {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const { data: tenant } = useCurrentTenant();
  const logout = useLogout();
  const managesBilling = hasPermission("billing.manage");
  const name = tenant?.name ?? "";

  return (
    <main className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8">
      <div className="flex items-start justify-between gap-4">
        <Alert variant="warning" className="flex-1">
          <AlertTitle>
            {t("billing.only_title", ":name's subscription has ended", {
              name,
            })}
          </AlertTitle>
          <AlertDescription>
            {managesBilling
              ? t(
                  "billing.only_admin",
                  "Everything else is closed until the bill is paid. Your data is kept. Pay the bill below, or choose a plan, and ETHR reopens the account once the payment is confirmed.",
                )
              : t(
                  "billing.only_member",
                  "Everything is closed until the bill is paid. Your data is kept. Ask your organisation's administrator to renew the subscription.",
                )}
          </AlertDescription>
        </Alert>
        <Button
          variant="outline"
          onClick={() => logout.mutate()}
          disabled={logout.isPending}
        >
          <LogOut className="mr-2 h-4 w-4" aria-hidden="true" />
          {t("common.logout", "Log Out")}
        </Button>
      </div>
      {managesBilling && <BillingPage />}
    </main>
  );
}
