"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { KeyRound, Loader2, Save } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useConfirm } from "@/components/shared/use-confirm";
import { toastError } from "@/lib/errors";
import { useSaveStatus } from "@/components/shared/save-status";
import { useUnsavedChangesWarning } from "@/lib/hooks/useUnsavedChangesWarning";
import { useT } from "@/lib/i18n/useT";
import {
  useAccessPolicy,
  useUpdateAccessPolicy,
} from "@/features/onboarding/v2/api";
import type { LoginIdentifier } from "@/features/onboarding/v2/types";

/**
 * Settings > General > Security: which identifiers people can sign in with.
 *
 * It saves through `PUT /onboarding/access`, the same validated endpoint the
 * setup wizard uses, so there is one place the list is checked. After setup has
 * finished the server leaves the wizard's own record as it was.
 *
 * The saved order is kept for what stays on (the first identifier that finds
 * exactly one person wins at sign-in), and `role_defaults` is not sent, so the
 * server keeps what is stored.
 */
export function LoginIdentifiersCard() {
  const { t } = useT();
  const policyQuery = useAccessPolicy();
  const queryClient = useQueryClient();
  const update = useUpdateAccessPolicy();
  const { requestConfirm, confirmDialog } = useConfirm();
  const { announceSaved, announceError } = useSaveStatus();
  const [selected, setSelected] = useState<LoginIdentifier[] | null>(null);
  // The card renders inside QueryBoundary, so the saved list is read from the
  // query here: a hook cannot be called inside the render function below.
  const savedIdentifiers = policyQuery.data?.login_identifiers;
  useUnsavedChangesWarning(
    selected !== null &&
      savedIdentifiers !== undefined &&
      !(
        selected.length === savedIdentifiers.length &&
        selected.every((id) => savedIdentifiers.includes(id))
      ),
  );

  const label = (id: LoginIdentifier): string =>
    ({
      email: t("setup2.id_email", "Email address"),
      phone: t("setup2.id_phone", "Mobile number"),
      employee_code: t("setup2.id_code", "Employee number"),
      username: t("setup2.id_username", "Username"),
    })[id];

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <KeyRound className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-base">
            {t("settings.login_identifiers_title", "Sign-in methods")}
          </CardTitle>
        </div>
        <p className="text-sm text-muted-foreground">
          {t(
            "settings.login_identifiers_help",
            "What people can type to sign in. Choose at least one.",
          )}
        </p>
      </CardHeader>
      <CardContent>
        <QueryBoundary query={policyQuery}>
          {(policy) => {
            const saved = policy.login_identifiers;
            const current = selected ?? saved;
            const unchanged =
              current.length === saved.length &&
              current.every((id) => saved.includes(id));
            const none = current.length === 0;

            function toggle(id: LoginIdentifier) {
              const on = current.includes(id);
              // Kept in the saved order, with anything new after it.
              const next = on
                ? current.filter((x) => x !== id)
                : [...current, id];
              setSelected(next);
            }

            async function save() {
              if (none || unchanged) return;
              const removed = saved.filter((id) => !current.includes(id));
              if (removed.length > 0) {
                const ok = await requestConfirm({
                  title: t(
                    "settings.login_identifiers_remove_title",
                    "Remove a sign-in method?",
                  ),
                  description: t(
                    "settings.login_identifiers_remove_body",
                    "People who sign in with :methods will have to use another method. Check that each of them has one that still works.",
                    { methods: removed.map(label).join(", ") },
                  ),
                  confirmLabel: t(
                    "settings.login_identifiers_remove_confirm",
                    "Remove and save",
                  ),
                  variant: "destructive",
                });
                if (!ok) return;
              }
              const ordered = [
                ...saved.filter((id) => current.includes(id)),
                ...current.filter((id) => !saved.includes(id)),
              ];
              update.mutate(
                { login_identifiers: ordered },
                {
                  onSuccess: () => {
                    setSelected(null);
                    queryClient.invalidateQueries({
                      queryKey: ["onboarding", "progress"],
                    });
                    toast.success(
                      t(
                        "settings.login_identifiers_saved",
                        "Sign-in methods saved",
                      ),
                    );
                    announceSaved(
                      t(
                        "settings.login_identifiers_saved",
                        "Sign-in methods saved",
                      ),
                    );
                  },
                  onError: (error) => {
                    announceError(error);
                    toastError(
                      error,
                      t("settings.save_failed", "Failed to save settings"),
                    );
                  },
                },
              );
            }

            return (
              <div className="space-y-4">
                <div className="space-y-3">
                  {policy.available.map((id) => (
                    <div key={id} className="flex items-center gap-3">
                      <Checkbox
                        id={`login-identifier-${id}`}
                        checked={current.includes(id)}
                        onCheckedChange={() => toggle(id)}
                      />
                      <label
                        htmlFor={`login-identifier-${id}`}
                        className="text-sm font-medium"
                      >
                        {label(id)}
                      </label>
                    </div>
                  ))}
                </div>
                {none && (
                  <p className="text-xs text-destructive" role="alert">
                    {t(
                      "settings.login_identifiers_none",
                      "Choose at least one sign-in method.",
                    )}
                  </p>
                )}
                <div className="flex justify-end">
                  <Button
                    onClick={save}
                    disabled={none || unchanged || update.isPending}
                  >
                    {update.isPending ? (
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                      <Save className="mr-2 h-4 w-4" />
                    )}
                    {t(
                      "settings.login_identifiers_save",
                      "Save sign-in methods",
                    )}
                  </Button>
                </div>
              </div>
            );
          }}
        </QueryBoundary>
        {confirmDialog}
      </CardContent>
    </Card>
  );
}
