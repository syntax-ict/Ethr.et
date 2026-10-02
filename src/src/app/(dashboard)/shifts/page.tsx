"use client";

import { useState } from "react";
import {
  Clock,
  Plus,
  Pencil,
  Trash2,
  MoreVertical,
  Loader2,
  Star,
  Moon,
  CheckCircle2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useCreateShift,
  useDeleteShift,
  useShifts,
  useUpdateShift,
  type Shift,
} from "@/features/shifts/api";
import { parseWorkingDays, toHHMM } from "@/features/shifts/dates";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import Link from "next/link";
import { cn } from "@/lib/utils";
import { SettingRow } from "@/components/patterns/SettingRow";

const DAY_LABELS = ["", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
const DEFAULT_WORKING_DAYS = "1,2,3,4,5";

interface ShiftForm {
  name: string;
  name_am: string;
  start_time: string;
  end_time: string;
  crosses_midnight: boolean;
  grace_minutes: number;
  break_minutes: number;
  early_departure_minutes: number;
  working_days: string;
  is_default: boolean;
  is_active: boolean;
}

const EMPTY_FORM: ShiftForm = {
  name: "",
  name_am: "",
  start_time: "08:30",
  end_time: "17:30",
  crosses_midnight: false,
  grace_minutes: 15,
  break_minutes: 60,
  early_departure_minutes: 15,
  working_days: DEFAULT_WORKING_DAYS,
  is_default: false,
  is_active: true,
};

function workedHours(
  start: string,
  end: string,
  crossesMidnight: boolean,
  breakMins: number,
): string {
  const [sh, sm] = start.split(":").map(Number);
  const [eh, em] = end.split(":").map(Number);
  let mins = eh * 60 + em - (sh * 60 + sm);
  if (crossesMidnight || mins < 0) mins += 24 * 60;
  mins -= breakMins;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

export default function ShiftsPage() {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  // Deleting is `shift.delete`, a tenant-admin grant; this page admits HR
  // admins, who hold create/update but not delete. The item was offered to
  // them anyway and answered with a 403.
  const canDelete = hasPermission("shift.delete");
  const [showDialog, setShowDialog] = useState(false);
  const [editing, setEditing] = useState<Shift | null>(null);
  const [form, setForm] = useState<ShiftForm>(EMPTY_FORM);
  const [search, setSearch] = useState("");

  const { data, isLoading } = useShifts();
  const createShift = useCreateShift();
  const updateShift = useUpdateShift();
  const deleteShift = useDeleteShift();
  const saving = createShift.isPending || updateShift.isPending;

  const shifts = data?.data ?? [];
  const filtered = search
    ? shifts.filter((s) => s.name.toLowerCase().includes(search.toLowerCase()))
    : shifts;

  function onSaveError(err: unknown) {
    const e = err as { response?: { data?: { detail?: string } } };
    toast.error(e.response?.data?.detail ?? t("shifts_page.save_failed"));
  }

  function save() {
    const body = {
      ...form,
      name_am: form.name_am || null,
      grace_minutes: Number(form.grace_minutes),
      break_minutes: Number(form.break_minutes),
      early_departure_minutes: Number(form.early_departure_minutes),
    };

    if (editing) {
      updateShift.mutate(
        { publicId: editing.public_id, payload: body },
        {
          onSuccess: () => {
            setShowDialog(false);
            toast.success(t("shifts_page.updated"));
          },
          onError: onSaveError,
        },
      );
      return;
    }

    createShift.mutate(body, {
      onSuccess: () => {
        setShowDialog(false);
        toast.success(t("shifts_settings_page.created"));
      },
      onError: onSaveError,
    });
  }

  function remove(shift: Shift) {
    if (!confirm(t("shifts_settings_page.delete_confirm"))) return;

    deleteShift.mutate(shift.public_id, {
      onSuccess: () => toast.success(t("shifts_settings_page.deleted")),
      // The API's own sentence, whatever the reason: 403 for permission, or
      // 409 while the shift has a current or upcoming assignment or is a
      // rotation step — its `detail` says which, and how many.
      onError: (err: unknown) => {
        const e = err as { response?: { data?: { detail?: string } } };
        toast.error(
          e.response?.data?.detail ?? t("shifts_settings_page.delete_failed"),
        );
      },
    });
  }

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setShowDialog(true);
  }

  function openEdit(s: Shift) {
    setEditing(s);
    setForm({
      name: s.name,
      name_am: s.name_am ?? "",
      // The API returns `HH:MM:SS` from MariaDB but accepts only `HH:MM`, so
      // echoing the value back unchanged made every edit a 422.
      start_time: toHHMM(s.start_time),
      end_time: toHHMM(s.end_time),
      crosses_midnight: s.crosses_midnight,
      grace_minutes: s.grace_minutes,
      break_minutes: s.break_minutes,
      early_departure_minutes: s.early_departure_minutes,
      working_days: s.working_days,
      is_default: s.is_default,
      is_active: s.is_active,
    });
    setShowDialog(true);
  }

  function toggleDay(day: number) {
    const days = parseWorkingDays(form.working_days);
    const next = days.includes(day)
      ? days.filter((d) => d !== day)
      : [...days, day].sort();
    setForm((f) => ({ ...f, working_days: next.join(",") }));
  }

  const activeDays = parseWorkingDays(form.working_days);

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("shifts_page.title")}
          description={t("shifts_page.description")}
          actions={
            <div className="flex gap-2">
              <Link href="/shifts/roster">
                <Button variant="outline">
                  {t("shifts_page.view_roster")}
                </Button>
              </Link>
              <Link href="/shifts/assignments">
                <Button variant="outline">
                  {t("shifts_page.assignments")}
                </Button>
              </Link>
              <Link href="/shifts/rotations">
                <Button variant="outline">
                  {t("shifts_page.rotations", "Rotations")}
                </Button>
              </Link>
              <Button onClick={openCreate}>
                <Plus className="mr-2 h-4 w-4" /> {t("shifts_page.new_shift")}
              </Button>
            </div>
          }
        />

        {/* Search */}
        <Input
          placeholder={t("shifts_page.search_placeholder")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="max-w-xs"
        />

        {/* Shift list */}
        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-40" />
            ))}
          </div>
        ) : filtered.length === 0 ? (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-16 text-center">
              <Clock className="h-12 w-12 text-muted-foreground/40" />
              <p className="mt-3 font-medium">
                {search
                  ? t("shifts_page.no_match")
                  : t("shifts_page.no_shifts_yet")}
              </p>
              <p className="mt-1 text-sm text-muted-foreground">
                {search ? "" : t("shifts_page.create_first_hint")}
              </p>
              {!search && (
                <Button className="mt-4" onClick={openCreate}>
                  <Plus className="mr-2 h-4 w-4" />{" "}
                  {t("shifts_page.create_shift")}
                </Button>
              )}
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {filtered.map((shift) => (
              <Card
                key={shift.public_id}
                className={cn(!shift.is_active && "opacity-60")}
              >
                <CardContent className="p-5">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1.5 flex-wrap">
                        <p className="font-semibold truncate">{shift.name}</p>
                        {shift.is_default && (
                          <Badge
                            variant="secondary"
                            className="text-xs shrink-0"
                          >
                            <Star className="mr-1 h-2.5 w-2.5" />{" "}
                            {t("shifts_settings_page.default")}
                          </Badge>
                        )}
                        {shift.crosses_midnight && (
                          <Badge variant="outline" className="text-xs shrink-0">
                            <Moon className="mr-1 h-2.5 w-2.5" />{" "}
                            {t("shifts_page.night")}
                          </Badge>
                        )}
                      </div>
                      <p className="mt-1 text-2xl font-mono font-bold tabular-nums text-primary">
                        {toHHMM(shift.start_time)}{" "}
                        <span className="text-muted-foreground text-lg">→</span>{" "}
                        {toHHMM(shift.end_time)}
                      </p>
                      <p className="text-xs text-muted-foreground mt-0.5">
                        {workedHours(
                          shift.start_time,
                          shift.end_time,
                          shift.crosses_midnight,
                          shift.break_minutes,
                        )}{" "}
                        {t("shifts_page.worked")}
                        {shift.break_minutes > 0 &&
                          ` · ${shift.break_minutes}m break`}
                        {` · ${shift.grace_minutes}m grace`}
                      </p>
                    </div>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7 shrink-0"
                          aria-label={t("common.actions", "Actions")}
                        >
                          <MoreVertical className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => openEdit(shift)}>
                          <Pencil className="mr-2 h-3.5 w-3.5" />{" "}
                          {t("common.edit")}
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                          <Link
                            href={`/shifts/assignments?shift=${shift.public_id}`}
                          >
                            <CheckCircle2 className="mr-2 h-3.5 w-3.5" />{" "}
                            {t("shifts_settings_page.assign")}
                          </Link>
                        </DropdownMenuItem>
                        {canDelete && (
                          <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              className="text-destructive"
                              onClick={() => remove(shift)}
                            >
                              <Trash2 className="mr-2 h-3.5 w-3.5" />{" "}
                              {t("common.delete")}
                            </DropdownMenuItem>
                          </>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>

                  {/* Working days pills */}
                  <div className="mt-3 flex gap-1 flex-wrap">
                    {[1, 2, 3, 4, 5, 6, 7].map((d) => {
                      const active = parseWorkingDays(
                        shift.working_days,
                      ).includes(d);
                      return (
                        <span
                          key={d}
                          className={cn(
                            "rounded px-1.5 py-0.5 text-xs font-medium",
                            active
                              ? "bg-primary-soft text-primary-on-soft"
                              : "bg-muted text-muted-foreground",
                          )}
                        >
                          {DAY_LABELS[d]}
                        </span>
                      );
                    })}
                    {shift.assignments_count !== undefined && (
                      <span className="ml-auto text-xs text-muted-foreground">
                        {shift.assignments_count}{" "}
                        {shift.assignments_count === 1
                          ? t("shifts_page.assignment_singular")
                          : t("shifts_page.assignment_plural")}
                      </span>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {/* Create / Edit Dialog */}
        <Dialog open={showDialog} onOpenChange={setShowDialog}>
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle>
                {editing
                  ? t("shifts_page.edit_shift")
                  : t("shifts_page.create_shift")}
              </DialogTitle>
              <DialogDescription>
                {editing
                  ? t("shifts_page.update_hint")
                  : t("shifts_page.define_hint")}
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4">
              {/* Name */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="name-english">
                    {t("shifts_page.name_english")}
                  </Label>
                  <Input
                    id="name-english"
                    value={form.name}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, name: e.target.value }))
                    }
                    placeholder={t(
                      "shifts_page.name_english_placeholder",
                      "Morning Shift",
                    )}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="name-amharic">
                    {t("shifts_page.name_amharic")}
                  </Label>
                  <Input
                    id="name-amharic"
                    value={form.name_am}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, name_am: e.target.value }))
                    }
                    placeholder="የጠዋት ፈረቃ"
                    className="mt-1"
                  />
                </div>
              </div>

              {/* Times */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="start-time-required">
                    {t("shifts_page.start_time_required")}
                  </Label>
                  <Input
                    id="start-time-required"
                    type="time"
                    value={form.start_time}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, start_time: e.target.value }))
                    }
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="end-time-required">
                    {t("shifts_page.end_time_required")}
                  </Label>
                  <Input
                    id="end-time-required"
                    type="time"
                    value={form.end_time}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, end_time: e.target.value }))
                    }
                    className="mt-1"
                  />
                </div>
              </div>

              {/* Duration preview */}
              {form.start_time && form.end_time && (
                <p className="text-sm text-muted-foreground -mt-2">
                  {t("shifts_page.worked_label")}:{" "}
                  <strong>
                    {workedHours(
                      form.start_time,
                      form.end_time,
                      form.crosses_midnight,
                      form.break_minutes,
                    )}
                  </strong>
                  {form.break_minutes > 0 &&
                    ` (${t("shifts_page.after")} ${form.break_minutes}m ${t("shifts_page.break_lc")})`}
                </p>
              )}

              {/* Minutes */}
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <Label htmlFor="grace-min">
                    {t("shifts_page.grace_min")}
                  </Label>
                  <Input
                    id="grace-min"
                    type="number"
                    value={form.grace_minutes}
                    onChange={(e) =>
                      setForm((f) => ({
                        ...f,
                        grace_minutes: Number(e.target.value),
                      }))
                    }
                    min={0}
                    max={120}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="break-min">
                    {t("shifts_page.break_min")}
                  </Label>
                  <Input
                    id="break-min"
                    type="number"
                    value={form.break_minutes}
                    onChange={(e) =>
                      setForm((f) => ({
                        ...f,
                        break_minutes: Number(e.target.value),
                      }))
                    }
                    min={0}
                    max={180}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="early-leave-min">
                    {t("shifts_page.early_leave_min")}
                  </Label>
                  <Input
                    id="early-leave-min"
                    type="number"
                    value={form.early_departure_minutes}
                    onChange={(e) =>
                      setForm((f) => ({
                        ...f,
                        early_departure_minutes: Number(e.target.value),
                      }))
                    }
                    min={0}
                    max={120}
                    className="mt-1"
                  />
                </div>
              </div>

              {/* Working days */}
              <div>
                <Label>{t("shifts_page.working_days")}</Label>
                <div className="mt-2 flex gap-2 flex-wrap">
                  {[1, 2, 3, 4, 5, 6, 7].map((d) => (
                    <button
                      key={d}
                      type="button"
                      onClick={() => toggleDay(d)}
                      className={cn(
                        "w-12 rounded-md py-1.5 text-xs font-medium transition-colors border",
                        activeDays.includes(d)
                          ? "bg-primary text-primary-foreground border-primary"
                          : "bg-background text-muted-foreground border-input hover:bg-muted",
                      )}
                    >
                      {DAY_LABELS[d]}
                    </button>
                  ))}
                </div>
              </div>

              {/* Toggles */}
              <div className="space-y-3 rounded-lg border p-3">
                <SettingRow
                  id="crosses-midnight"
                  title={t("shifts_page.crosses_midnight")}
                  description={t("shifts_page.crosses_midnight_desc")}
                >
                  <Switch
                    checked={form.crosses_midnight}
                    onCheckedChange={(v) =>
                      setForm((f) => ({ ...f, crosses_midnight: v }))
                    }
                  />
                </SettingRow>
                <SettingRow
                  id="default-shift"
                  title={t("shifts_page.default_shift")}
                  description={t("shifts_page.default_shift_desc")}
                >
                  <Switch
                    checked={form.is_default}
                    onCheckedChange={(v) =>
                      setForm((f) => ({ ...f, is_default: v }))
                    }
                  />
                </SettingRow>
                <SettingRow
                  id="active"
                  title={t("shifts_page.active")}
                  description={t("shifts_page.active_desc")}
                >
                  <Switch
                    checked={form.is_active}
                    onCheckedChange={(v) =>
                      setForm((f) => ({ ...f, is_active: v }))
                    }
                  />
                </SettingRow>
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setShowDialog(false)}>
                {t("common.cancel")}
              </Button>
              <Button
                onClick={save}
                disabled={
                  !form.name || !form.start_time || !form.end_time || saving
                }
              >
                {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {editing
                  ? t("leave_types_page.save_changes")
                  : t("shifts_page.create_shift")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
