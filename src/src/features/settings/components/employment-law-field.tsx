"use client";

import { Info } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useSaveStatus } from "@/components/shared/save-status";
import {
  useUpdateOrganization,
  type OrganizationUpdate,
  type TenantSettings,
} from "@/features/settings/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import {
  EMPLOYMENT_FRAMEWORKS,
  employmentFrameworkLabel,
} from "@/lib/options/employment-framework";

export type EmploymentFrameworkState =
  TenantSettings["organization"]["employment_framework"];

/**
 * The employment law that governs the organisation's staff.
 *
 * ETHR reads one for each kind of organisation, and says plainly that nobody
 * has confirmed it. The organisation can record its own, which is dated and
 * audited on the server, or go back to ETHR's reading. Both save at once and
 * on their own: they are not part of the organisation form's Save, so a name
 * fixed in a support session never carries a framework with it, which the
 * server refuses there.
 */
export function EmploymentLawField({
  state,
}: {
  state: EmploymentFrameworkState | null | undefined;
}) {
  const { t } = useT();
  const updateOrganization = useUpdateOrganization();
  const { announceSaved, announceError } = useSaveStatus();

  if (!state) return null;

  const own = state.source === "organisation";
  const unconfirmedReading = state.source === "ethr" && !state.confirmed;

  function send(value: string | null, savedMessage: string) {
    updateOrganization.mutate(
      {
        employment_framework:
          value as OrganizationUpdate["employment_framework"],
      },
      {
        onSuccess: () => {
          toast.success(savedMessage);
          announceSaved(savedMessage);
        },
        onError: (err) => {
          const fallback = t(
            "settings.employment_law_save_failed",
            "Could not record the employment law",
          );
          toast.error(apiErrorMessage(err, fallback));
          announceError(err, fallback);
        },
      },
    );
  }

  return (
    <div>
      <Label htmlFor="org-employment-law">
        {t("settings.employment_law", "Employment law")}
      </Label>
      <p
        className="mt-1 text-sm font-medium"
        data-testid="employment-law-current"
      >
        {state.framework
          ? employmentFrameworkLabel(t, state.framework)
          : t("employment_framework.unspecified", "Not specified")}
      </p>

      {state.framework === null && (
        <p role="status" className="mt-1 text-xs text-muted-foreground">
          {t(
            "settings.employment_law_none",
            "No employment law is on file for this kind of organisation. ETHR will ask rather than assume.",
          )}
        </p>
      )}
      {unconfirmedReading && (
        <p
          role="status"
          className="mt-1 flex items-start gap-1.5 rounded-md border border-warning-edge bg-warning-soft p-2 text-xs text-warning-on-soft"
        >
          <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          {t(
            "settings.employment_law_ethr_reading",
            "This is ETHR's reading for your kind of organisation. A lawyer has not confirmed it yet.",
          )}
        </p>
      )}
      {own && (
        <p className="mt-1 text-xs text-muted-foreground">
          {t("settings.employment_law_own", "Recorded by your organisation.")}
        </p>
      )}
      {state.legal_reference && (
        <p className="mt-1 text-xs text-muted-foreground">
          {t(
            "settings.employment_law_reference",
            "Legal reference: :reference",
            {
              reference: state.legal_reference,
            },
          )}
        </p>
      )}
      {state.framework !== null && !state.has_statutory_rules && (
        <p role="status" className="mt-1 text-xs text-muted-foreground">
          {t(
            "settings.employment_law_no_rules",
            "ETHR has no rules for this law yet, so it will apply none.",
          )}
        </p>
      )}

      <div className="mt-2 flex flex-wrap items-center gap-2">
        <Select
          value={own ? (state.framework ?? "") : ""}
          onValueChange={(value) =>
            send(
              value,
              t("settings.employment_law_saved", "Employment law recorded"),
            )
          }
          disabled={updateOrganization.isPending}
        >
          <SelectTrigger
            id="org-employment-law"
            className="w-full sm:w-72"
            aria-label={t(
              "settings.employment_law_choose",
              "Choose the law that governs your staff",
            )}
          >
            <SelectValue
              placeholder={t(
                "settings.employment_law_choose",
                "Choose the law that governs your staff",
              )}
            />
          </SelectTrigger>
          <SelectContent>
            {EMPLOYMENT_FRAMEWORKS.map((value) => (
              <SelectItem key={value} value={value}>
                {employmentFrameworkLabel(t, value)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {own && (
          <Button
            type="button"
            size="sm"
            variant="link"
            className="h-auto p-0 text-xs"
            disabled={updateOrganization.isPending}
            onClick={() =>
              send(
                null,
                t("settings.employment_law_reset", "Now using ETHR's reading"),
              )
            }
          >
            {t("settings.employment_law_use_ethr", "Use ETHR's reading")}
          </Button>
        )}
      </div>
    </div>
  );
}
