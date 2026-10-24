"use client";

import { useState } from "react";
import { Building2, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  useBranchAttendanceRules,
  useUpdateBranchAttendanceRules,
  type BranchAttendanceRow,
  type BranchOverrides,
} from "@/features/attendance/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

type Rules = BranchAttendanceRow["effective"];
type SwitchKey =
  | "geofence_required"
  | "mobile_photo_required"
  | "kiosk_pin_required"
  | "auto_checkout_enabled"
  | "geofence_auto_checkin_enabled";

const SWITCHES: SwitchKey[] = [
  "geofence_required",
  "mobile_photo_required",
  "kiosk_pin_required",
  "auto_checkout_enabled",
  "geofence_auto_checkin_enabled",
];

/**
 * Attendance rules a branch sets differently from the organisation. Every
 * rule starts as "Same as organisation"; only what a branch changes is
 * stored, so a later change at the organisation still reaches it.
 */
export function BranchRulesCard() {
  const { t } = useT();
  const { data, isLoading } = useBranchAttendanceRules();
  const [editing, setEditing] = useState<BranchAttendanceRow | null>(null);

  const switchLabel: Record<SwitchKey, string> = {
    geofence_required: t(
      "branch_rules.geofence_required",
      "Location must be inside the branch area",
    ),
    mobile_photo_required: t(
      "branch_rules.mobile_photo_required",
      "Selfie with phone check-in",
    ),
    kiosk_pin_required: t(
      "branch_rules.kiosk_pin_required",
      "PIN at the kiosk",
    ),
    auto_checkout_enabled: t(
      "branch_rules.auto_checkout_enabled",
      "Automatic check-out",
    ),
    geofence_auto_checkin_enabled: t(
      "branch_rules.geofence_auto_checkin_enabled",
      "Automatic check-in on arrival",
    ),
  };

  const changed = (row: BranchAttendanceRow) =>
    Object.values(row.overrides).filter((v) => v !== null).length;

  return (
    <Card className="lg:col-span-2">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Building2 className="h-4 w-4" />
          {t("branch_rules.title", "Rules by branch")}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {t(
            "branch_rules.description",
            "A branch follows the organisation's rules unless you change one here. Retention, confidence and overtime rules stay the same everywhere.",
          )}
        </p>
      </CardHeader>
      <CardContent>
        {isLoading || !data ? (
          <Skeleton className="h-24" />
        ) : data.branches.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {t("branch_rules.none", "No branches yet.")}
          </p>
        ) : (
          <ul className="divide-y divide-border">
            {data.branches.map((row) => (
              <li
                key={row.public_id}
                className="flex flex-wrap items-center justify-between gap-3 py-3"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{row.name}</p>
                  <div className="mt-1 flex flex-wrap gap-1.5">
                    {changed(row) === 0 ? (
                      <Badge variant="secondary">
                        {t("branch_rules.inherits", "Same as organisation")}
                      </Badge>
                    ) : (
                      <Badge>
                        {t("branch_rules.changed", ":count changed", {
                          count: changed(row),
                        })}
                      </Badge>
                    )}
                    {!row.placed && (
                      <Badge variant="outline">
                        {t("branch_rules.not_placed", "Not on the map")}
                      </Badge>
                    )}
                  </div>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setEditing(row)}
                >
                  {t("branch_rules.edit", "Change rules")}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      {editing && data && (
        <BranchRulesDialog
          row={editing}
          organisation={data.organisation}
          switchLabel={switchLabel}
          onClose={() => setEditing(null)}
        />
      )}
    </Card>
  );
}

function BranchRulesDialog({
  row,
  organisation,
  switchLabel,
  onClose,
}: {
  row: BranchAttendanceRow;
  organisation: Rules;
  switchLabel: Record<SwitchKey, string>;
  onClose: () => void;
}) {
  const { t } = useT();
  const save = useUpdateBranchAttendanceRules();
  const [draft, setDraft] = useState<BranchOverrides>({
    ...(row.overrides as BranchOverrides),
  });

  const onOff = (value: boolean | null | undefined) =>
    value ? t("branch_rules.on", "On") : t("branch_rules.off", "Off");

  function setSwitch(key: SwitchKey, value: string) {
    setDraft({
      ...draft,
      [key]: value === "inherit" ? null : value === "on",
    });
  }

  function submit() {
    save.mutate(
      { branch: row.public_id, overrides: draft },
      {
        onSuccess: () => {
          toast.success(t("branch_rules.saved", "Branch rules saved"));
          onClose();
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("branch_rules.save_failed", "Couldn't save the branch rules"),
            ),
          ),
      },
    );
  }

  const accuracy = draft.mobile_accuracy_threshold_meters;

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{row.name}</DialogTitle>
          <DialogDescription>
            {t(
              "branch_rules.dialog_description",
              "Choose a value only where this branch differs. Anything left as the organisation's follows it when it changes.",
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {SWITCHES.map((key) => {
            const current = draft[key];
            return (
              <div
                key={key}
                className="flex flex-wrap items-center justify-between gap-2"
              >
                <Label className="text-sm">{switchLabel[key]}</Label>
                <Select
                  value={
                    current === null || current === undefined
                      ? "inherit"
                      : current
                        ? "on"
                        : "off"
                  }
                  onValueChange={(v) => setSwitch(key, v)}
                >
                  <SelectTrigger
                    className="w-[210px]"
                    aria-label={switchLabel[key]}
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="inherit">
                      {t(
                        "branch_rules.inherit_value",
                        "Organisation (:value)",
                        { value: onOff(organisation[key]) },
                      )}
                    </SelectItem>
                    <SelectItem value="on">
                      {t("branch_rules.on", "On")}
                    </SelectItem>
                    <SelectItem value="off">
                      {t("branch_rules.off", "Off")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
            );
          })}

          <div className="flex flex-wrap items-center justify-between gap-2">
            <Label htmlFor="branch-accuracy" className="text-sm">
              {t("branch_rules.accuracy", "Weakest GPS accepted (metres)")}
            </Label>
            <Input
              id="branch-accuracy"
              type="number"
              min={10}
              max={5000}
              className="w-[210px]"
              placeholder={t(
                "branch_rules.inherit_value",
                "Organisation (:value)",
                {
                  value: String(
                    organisation.mobile_accuracy_threshold_meters ?? "",
                  ),
                },
              )}
              value={accuracy ?? ""}
              onChange={(e) =>
                setDraft({
                  ...draft,
                  mobile_accuracy_threshold_meters:
                    e.target.value === "" ? null : parseInt(e.target.value, 10),
                })
              }
            />
          </div>
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button
            variant="outline"
            onClick={() =>
              setDraft(
                Object.fromEntries(
                  Object.keys(draft).map((k) => [k, null]),
                ) as BranchOverrides,
              )
            }
          >
            {t("branch_rules.reset", "Same as organisation")}
          </Button>
          <Button onClick={submit} disabled={save.isPending}>
            {save.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("common.save", "Save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
