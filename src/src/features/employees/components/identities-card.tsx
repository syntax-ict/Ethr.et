"use client";

import { Fingerprint, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useConfirm } from "@/components/shared/use-confirm";
import { toastError } from "@/lib/errors";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import {
  type CreatedVia,
  type EmployeeIdentity,
  useEmployeeIdentities,
  useUnlinkIdentity,
  useVerifyIdentity,
} from "../identities";

/**
 * What this employee is known as outside ETHR, and how their record was made
 * (One employee identity, PR 11).
 *
 * One person is one record here and several ids elsewhere: the number each
 * attendance terminal has for them, the id another HR or payroll system gave
 * them. Those links decide who a punch or an imported line is. They were
 * invisible; a wrong one could only be found by its effects. Here each is
 * shown with where it is from, and someone who may edit the employee can
 * confirm one or remove one that is wrong.
 */
export function IdentitiesCard({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const { requestConfirm, confirmDialog } = useConfirm();
  const query = useEmployeeIdentities(employeeId);
  const verify = useVerifyIdentity(employeeId);
  const unlink = useUnlinkIdentity(employeeId);
  const canEdit = hasPermission("employee.update");

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

  function where(identity: EmployeeIdentity): string {
    if (identity.kind === "device") {
      return (
        identity.source ??
        t("identities.device_unknown", "An attendance device")
      );
    }
    if (identity.kind === "other_system") {
      return identity.source ?? t("identities.other_system", "Another system");
    }
    return identity.source_type;
  }

  async function onVerify(identity: EmployeeIdentity) {
    try {
      await verify.mutateAsync(identity.public_id);
      toast.success(t("identities.verified_toast", "Link confirmed"));
    } catch (e) {
      toastError(e, t("common.error", "Something went wrong"));
    }
  }

  async function onUnlink(identity: EmployeeIdentity) {
    const confirmed = await requestConfirm({
      title: t("identities.unlink_title", "Remove this link?"),
      description: t(
        "identities.unlink_desc",
        ":where will no longer know this employee as :value. Their record is not changed. The next punch or list that brings this id is matched again, or held for you to assign.",
        { where: where(identity), value: identity.identifier_value ?? "" },
      ),
      confirmLabel: t("identities.unlink_confirm", "Remove the link"),
    });
    if (!confirmed) return;
    try {
      await unlink.mutateAsync(identity.public_id);
      toast.success(t("identities.unlinked_toast", "Link removed"));
    } catch (e) {
      toastError(e, t("common.error", "Something went wrong"));
    }
  }

  return (
    <Card>
      {confirmDialog}
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Fingerprint className="h-4 w-4" aria-hidden="true" />
          {t("identities.title", "Known elsewhere as")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <QueryBoundary query={query} isEmpty={() => false}>
          {(data) => (
            <>
              <p className="text-sm text-muted-foreground">
                {data.created_via
                  ? via[data.created_via]
                  : t(
                      "identities.via_unknown",
                      "How this record was made is not known. It is older than the day ETHR began recording it.",
                    )}
              </p>

              {data.identities.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  {t(
                    "identities.none",
                    "No attendance device or other system is linked to this employee yet.",
                  )}
                </p>
              ) : (
                <ul className="divide-y divide-border rounded-md border">
                  {data.identities.map((identity) => (
                    <li
                      key={identity.public_id}
                      className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                    >
                      <div className="min-w-0 text-sm">
                        <p className="truncate font-medium text-foreground">
                          {where(identity)}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {identity.kind === "device"
                            ? t("identities.device_number", "Number on device")
                            : t("identities.their_id", "Their id")}{" "}
                          <span className="font-mono text-foreground">
                            {identity.identifier_value ??
                              t("identities.hidden", "not shown")}
                          </span>
                        </p>
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge
                          variant={identity.verified ? "default" : "outline"}
                        >
                          {identity.verified
                            ? t("identities.confirmed", "Confirmed")
                            : t("identities.inferred", "Matched by ETHR")}
                        </Badge>
                        {canEdit && !identity.verified && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onVerify(identity)}
                            disabled={verify.isPending}
                          >
                            {verify.isPending &&
                              verify.variables === identity.public_id && (
                                <Loader2 className="mr-2 h-3 w-3 animate-spin" />
                              )}
                            {t("identities.confirm", "Confirm")}
                          </Button>
                        )}
                        {canEdit && (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => onUnlink(identity)}
                            disabled={unlink.isPending}
                          >
                            {t("identities.unlink", "Remove")}
                          </Button>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}
