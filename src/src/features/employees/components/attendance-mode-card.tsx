"use client";

import { MapPinned } from "lucide-react";
import { toast } from "sonner";
import { Card, CardContent } from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  useSetAttendanceMode,
  useSetDeviceExemption,
} from "@/features/employees/api";
import type { Employee } from "@/features/employees/types";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

type Mode = "office" | "field" | "remote";

const MODES: Mode[] = ["office", "field", "remote"];

/**
 * Where this employee is expected to punch. Office: their branch's area, as
 * the organisation set it. Field: any of their sites, and being elsewhere is
 * flagged for review, never refused. Remote: anywhere, with the location
 * still recorded.
 */
export function AttendanceModeCard({ employee }: { employee: Employee }) {
  const { t } = useT();
  const setMode = useSetAttendanceMode(employee.public_id);
  const setExemption = useSetDeviceExemption(employee.public_id);
  const [exemptUntil, setExemptUntil] = useState(
    employee.attendance_device_exempt_until ?? "",
  );

  function saveExemption(until: string | null) {
    setExemption.mutate(until, {
      onSuccess: () => {
        setExemptUntil(until ?? "");
        toast.success(t("attendance_mode.saved", "Saved"));
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("device_exemption.failed", "Couldn't change the exemption"),
          ),
        ),
    });
  }

  const label: Record<Mode, string> = {
    office: t("attendance_mode.office", "At their branch"),
    field: t("attendance_mode.field", "Field"),
    remote: t("attendance_mode.remote", "Remote"),
  };
  const hint: Record<Mode, string> = {
    office: t(
      "attendance_mode.office_hint",
      "Checked against their branch's area when your organisation requires a location.",
    ),
    field: t(
      "attendance_mode.field_hint",
      "Can punch at any of their sites. Punching anywhere else is sent for review, not refused.",
    ),
    remote: t(
      "attendance_mode.remote_hint",
      "Can punch from anywhere. The location is still recorded.",
    ),
  };

  const current = (
    MODES.includes(employee.attendance_mode as Mode)
      ? employee.attendance_mode
      : "office"
  ) as Mode;

  function change(next: string) {
    const mode = next as Mode;
    if (mode === current) return;
    setMode.mutate(mode, {
      onSuccess: () => toast.success(t("attendance_mode.saved", "Saved")),
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("attendance_mode.failed", "Couldn't change where they punch"),
          ),
        ),
    });
  }

  return (
    <Card>
      <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="flex min-w-0 items-center gap-3">
          <MapPinned className="h-4 w-4 shrink-0 text-muted-foreground" />
          <div className="min-w-0">
            <p className="text-sm font-medium text-foreground">
              {t("attendance_mode.title", "Where they punch")}
            </p>
            <p className="text-xs text-muted-foreground">{hint[current]}</p>
          </div>
        </div>
        <Select
          value={current}
          onValueChange={change}
          disabled={setMode.isPending}
        >
          <SelectTrigger
            className="w-[180px]"
            aria-label={t("attendance_mode.title", "Where they punch")}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {MODES.map((mode) => (
              <SelectItem key={mode} value={mode}>
                {label[mode]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <div className="flex w-full flex-wrap items-end justify-between gap-2 border-t border-border pt-3">
          <div className="min-w-0">
            <p className="text-sm font-medium text-foreground">
              {t(
                "device_exemption.title",
                "Punch without the registered phone",
              )}
            </p>
            <p className="text-xs text-muted-foreground">
              {t(
                "device_exemption.hint",
                "Until this date, for a phone that cannot hold a passkey or is broken. Their punches are still sent for review.",
              )}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Input
              type="date"
              className="w-40"
              aria-label={t(
                "device_exemption.title",
                "Punch without the registered phone",
              )}
              value={exemptUntil}
              onChange={(e) => setExemptUntil(e.target.value)}
            />
            <Button
              size="sm"
              variant="outline"
              disabled={setExemption.isPending || exemptUntil === ""}
              onClick={() => saveExemption(exemptUntil)}
            >
              {t("device_exemption.save", "Save")}
            </Button>
            {employee.attendance_device_exempt_until && (
              <Button
                size="sm"
                variant="ghost"
                disabled={setExemption.isPending}
                onClick={() => saveExemption(null)}
              >
                {t("device_exemption.end", "End")}
              </Button>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
