"use client";

import { Badge } from "@/components/ui/badge";
import { useT } from "@/lib/i18n/useT";
import { isCurrentStaff } from "../sign-in";
import type { Employee } from "../types";

/**
 * A quiet note beside a person who cannot sign in yet: nobody has given them
 * a way to, or they were invited and have not finished. Nothing for anyone
 * else, and nothing when the list did not say (`sign_in` is only on the
 * employees list).
 */
export function SignInBadge({
  state,
  status,
}: {
  state: Employee["sign_in"];
  status?: string | null;
}) {
  const { t } = useT();

  // Someone who has left needs no way in, so the note would only be noise.
  if (!isCurrentStaff(status)) return null;

  if (state === "none") {
    return (
      <Badge variant="neutral" className="font-normal">
        {t("employees.sign_in.none", "No sign-in")}
      </Badge>
    );
  }

  if (state === "invited") {
    return (
      <Badge variant="info" className="font-normal">
        {t("employees.sign_in.invited", "Invited, not activated")}
      </Badge>
    );
  }

  return null;
}
