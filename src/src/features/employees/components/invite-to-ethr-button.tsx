"use client";

import { Loader2, Send } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { useInviteUser } from "@/features/users/api";
import { fieldErrors, toastError } from "@/lib/errors";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * Give an existing employee an ETHR login, linked to their record.
 *
 * The API always accepted `employee_id` on `POST /users`, but no screen sent
 * it: Settings → Users invited a login linked to nobody, and the profile had no
 * action at all, so an employee could never request leave, see a payslip or
 * check in as themselves (production QA 2026-10-11, P15).
 *
 * Whether the employee already has a login is not in the employee resource, so
 * this asks rather than guesses: an address that already signs in is refused
 * by the server (`email` taken), and that is shown as such.
 */
export function InviteToEthrButton({
  employeeId,
  email,
}: {
  employeeId: string;
  email: string | null | undefined;
}) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const invite = useInviteUser();

  if (!email || !hasPermission("users.invite")) return null;

  async function send() {
    try {
      await invite.mutateAsync({
        email: email as string,
        role: "employee",
        employee_id: employeeId,
        send_activation: true,
      });
      toast.success(
        t("employee.login_invited", "Login invitation sent to :email", {
          email: email as string,
        }),
      );
    } catch (e) {
      if (fieldErrors(e).email) {
        toast.info(
          t(
            "employee.login_exists",
            "This email already signs in to ETHR — manage it under Settings → Users.",
          ),
        );
        return;
      }
      toastError(
        e,
        t("employee.login_invite_failed", "Could not send the invitation"),
      );
    }
  }

  return (
    <Button
      variant="outline"
      size="sm"
      onClick={send}
      disabled={invite.isPending}
    >
      {invite.isPending ? (
        <Loader2 className="mr-2 h-3 w-3 animate-spin" />
      ) : (
        <Send className="mr-2 h-3 w-3" />
      )}
      {t("employee.invite_to_ethr", "Invite to ETHR")}
    </Button>
  );
}
