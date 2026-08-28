"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { CalendarSync, Plus, Loader2, Moon, ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { SimpleTable } from "@/components/shared/simple-table";
import { QueryBoundary } from "@/components/patterns";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useShifts } from "@/features/shifts/api";
import {
  useShiftRotations,
  useCreateShiftRotation,
  useUpdateShiftRotation,
  useDeleteShiftRotation,
  useRotationPreview,
  useAssignShiftRotation,
  resizeSteps,
  type ShiftRotation,
  type ShiftRotationStepInput,
} from "@/features/shifts/rotations";

/** Sentinel for the rest-day option: Radix Select cannot hold an empty value. */
const REST = "__rest__";

const DEFAULT_CYCLE = 14;

interface RotationForm {
  name: string;
  name_am: string;
  description: string;
  cycle_days: number;
  is_active: boolean;
  steps: ShiftRotationStepInput[];
}

const EMPTY_FORM: RotationForm = {
  name: "",
  name_am: "",
  description: "",
  cycle_days: DEFAULT_CYCLE,
  is_active: true,
  steps: resizeSteps([], DEFAULT_CYCLE),
};

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

function addDaysIso(iso: string, days: number): string {
  const d = new Date(iso + "T00:00:00");
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

export default function ShiftRotationsPage() {
  const { t } = useT();
  const [showDialog, setShowDialog] = useState(false);
  const [editing, setEditing] = useState<ShiftRotation | null>(null);
  const [form, setForm] = useState<RotationForm>(EMPTY_FORM);
  const [previewOf, setPreviewOf] = useState<ShiftRotation | null>(null);
  const [assigning, setAssigning] = useState<ShiftRotation | null>(null);

  const rotationsQuery = useShiftRotations({ per_page: 100 });
  const { data: shiftsData } = useShifts({ per_page: 100 });
  const shifts = useMemo(() => shiftsData?.data ?? [], [shiftsData]);

  const create = useCreateShiftRotation();
  const update = useUpdateShiftRotation(editing?.public_id ?? "");
  const destroy = useDeleteShiftRotation();
  const saving = create.isPending || update.isPending;

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setShowDialog(true);
  }

  function openEdit(rotation: ShiftRotation) {
    setEditing(rotation);
    setForm({
      name: rotation.name,
      name_am: rotation.name_am ?? "",
      description: rotation.description ?? "",
      cycle_days: rotation.cycle_days,
      is_active: rotation.is_active,
      steps: resizeSteps(
        (rotation.steps ?? []).map((s) => ({
          day_offset: s.day_offset,
          shift_id: s.shift?.public_id ?? null,
        })),
        rotation.cycle_days,
      ),
    });
    setShowDialog(true);
  }

  function setCycleDays(next: number) {
    // Steps are regenerated rather than merely appended to: shrinking the cycle
    // must drop the now-unreachable tail, which the API rejects outright.
    setForm((f) => ({
      ...f,
      cycle_days: next,
      steps: resizeSteps(f.steps, next),
    }));
  }

  function setStepShift(offset: number, shiftId: string | null) {
    setForm((f) => ({
      ...f,
      steps: f.steps.map((s) =>
        s.day_offset === offset ? { ...s, shift_id: shiftId } : s,
      ),
    }));
  }

  async function save() {
    const payload = {
      name: form.name,
      name_am: form.name_am || null,
      description: form.description || null,
      cycle_days: form.cycle_days,
      is_active: form.is_active,
      steps: form.steps,
    };

    try {
      if (editing) {
        await update.mutateAsync(payload);
        toast.success(t("rotations_page.updated", "Rotation updated"));
      } else {
        await create.mutateAsync(payload);
        toast.success(t("rotations_page.created", "Rotation created"));
      }
      setShowDialog(false);
    } catch (err: unknown) {
      const e = err as { response?: { data?: { detail?: string } } };
      toast.error(
        e.response?.data?.detail ??
          t("rotations_page.save_failed", "Could not save rotation"),
      );
    }
  }

  async function remove(rotation: ShiftRotation) {
    try {
      await destroy.mutateAsync(rotation.public_id);
      toast.success(t("rotations_page.deleted", "Rotation deleted"));
    } catch {
      toast.error(
        t("rotations_page.delete_failed", "Could not delete rotation"),
      );
    }
  }

  // Gated at the same tier as the shifts page it sits beside: a rotation is a
  // way of scheduling shifts, so the same people manage both.
  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("rotations_page.title", "Shift Rotations")}
          description={t(
            "rotations_page.description",
            "Repeating patterns for teams that do not work the same shift every week.",
          )}
          actions={
            <div className="flex gap-2">
              <Link href="/shifts">
                <Button variant="outline">
                  <ArrowLeft className="mr-2 h-4 w-4" />
                  {t("rotations_page.back_to_shifts", "Shifts")}
                </Button>
              </Link>
              <Button onClick={openCreate}>
                <Plus className="mr-2 h-4 w-4" />
                {t("rotations_page.new_rotation", "New rotation")}
              </Button>
            </div>
          }
        />

        <QueryBoundary
          query={rotationsQuery}
          isEmpty={(d) => (d?.data?.length ?? 0) === 0}
          empty={
            <Card>
              <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                <CalendarSync className="h-8 w-8 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">
                  {t(
                    "rotations_page.empty",
                    "No rotations yet. Create one to schedule teams that cycle through shifts.",
                  )}
                </p>
                <Button onClick={openCreate}>
                  <Plus className="mr-2 h-4 w-4" />
                  {t("rotations_page.new_rotation", "New rotation")}
                </Button>
              </CardContent>
            </Card>
          }
        >
          {(data) => (
            <Card>
              <CardContent className="pt-6">
                <SimpleTable
                  caption={t("rotations_page.title", "Shift Rotations")}
                  headers={[
                    t("rotations_page.name", "Name"),
                    t("rotations_page.cycle", "Cycle"),
                    t("rotations_page.pattern", "Pattern"),
                    t("rotations_page.status", "Status"),
                  ]}
                  align={["left", "right", "left", "left"]}
                  colClassName={["", "", "hidden md:table-cell", ""]}
                  rows={data.data.map((rotation) => ({
                    key: rotation.public_id,
                    cells: [
                      <span key="n" className="font-medium">
                        {rotation.name}
                      </span>,
                      t("rotations_page.n_days", "{{count}} days").replace(
                        "{{count}}",
                        String(rotation.cycle_days),
                      ),
                      <span key="p" className="text-muted-foreground">
                        {
                          (rotation.steps ?? []).filter((s) => !s.is_rest_day)
                            .length
                        }{" "}
                        {t("rotations_page.working_days", "working")} ·{" "}
                        {
                          (rotation.steps ?? []).filter((s) => s.is_rest_day)
                            .length
                        }{" "}
                        {t("rotations_page.rest_days", "rest")}
                      </span>,
                      <Badge
                        key="s"
                        variant={rotation.is_active ? "default" : "secondary"}
                      >
                        {rotation.is_active
                          ? t("common.active", "Active")
                          : t("common.inactive", "Inactive")}
                      </Badge>,
                    ],
                    actions: (
                      <div className="flex justify-end gap-1">
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => setPreviewOf(rotation)}
                        >
                          {t("rotations_page.preview", "Preview")}
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => setAssigning(rotation)}
                        >
                          {t("rotations_page.assign", "Assign")}
                        </Button>
                      </div>
                    ),
                    onEdit: () => openEdit(rotation),
                    onDelete: () => remove(rotation),
                  }))}
                />
              </CardContent>
            </Card>
          )}
        </QueryBoundary>
      </div>

      <Dialog open={showDialog} onOpenChange={setShowDialog}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>
              {editing
                ? t("rotations_page.edit_title", "Edit rotation")
                : t("rotations_page.new_rotation", "New rotation")}
            </DialogTitle>
            <DialogDescription>
              {t(
                "rotations_page.dialog_description",
                "Choose a shift for each day of the cycle. Days left as Rest are days off.",
              )}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="rotation-name">
                  {t("rotations_page.name", "Name")}
                </Label>
                <Input
                  id="rotation-name"
                  value={form.name}
                  onChange={(e) =>
                    setForm((f) => ({ ...f, name: e.target.value }))
                  }
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="rotation-cycle">
                  {t("rotations_page.cycle_days", "Cycle length (days)")}
                </Label>
                <Input
                  id="rotation-cycle"
                  type="number"
                  min={1}
                  max={366}
                  value={form.cycle_days}
                  onChange={(e) => setCycleDays(Number(e.target.value) || 1)}
                />
                <p className="text-xs text-muted-foreground">
                  {t(
                    "rotations_page.cycle_hint",
                    "7 for weekly, 14 for fortnightly, 8 for four-on-four-off.",
                  )}
                </p>
              </div>
            </div>

            <div className="space-y-2">
              <Label>{t("rotations_page.pattern", "Pattern")}</Label>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                {form.steps.map((step) => (
                  <div
                    key={step.day_offset}
                    className={cn(
                      "rounded-md border p-2",
                      step.shift_id === null && "bg-muted/50",
                    )}
                  >
                    <div className="mb-1 flex items-center justify-between">
                      <span className="text-xs font-medium text-muted-foreground">
                        {t("rotations_page.day", "Day")} {step.day_offset + 1}
                      </span>
                      {step.shift_id === null && (
                        <Moon
                          className="h-3 w-3 text-muted-foreground"
                          aria-hidden="true"
                        />
                      )}
                    </div>
                    <Select
                      value={step.shift_id ?? REST}
                      onValueChange={(v) =>
                        setStepShift(step.day_offset, v === REST ? null : v)
                      }
                    >
                      <SelectTrigger
                        aria-label={`${t("rotations_page.day", "Day")} ${step.day_offset + 1}`}
                      >
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value={REST}>
                          {t("rotations_page.rest", "Rest")}
                        </SelectItem>
                        {shifts.map((s) => (
                          <SelectItem key={s.public_id} value={s.public_id}>
                            {s.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                ))}
              </div>
            </div>

            <div className="flex items-center gap-2">
              <Switch
                aria-label={t("shifts.rotation_active", "Rotation active")}
                id="rotation-active"
                checked={form.is_active}
                onCheckedChange={(v) =>
                  setForm((f) => ({ ...f, is_active: v }))
                }
              />
              <Label htmlFor="rotation-active">
                {t("common.active", "Active")}
              </Label>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDialog(false)}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button onClick={save} disabled={saving || !form.name}>
              {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {t("common.save", "Save")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <RotationPreviewDialog
        rotation={previewOf}
        onClose={() => setPreviewOf(null)}
      />

      <RotationAssignDialog
        rotation={assigning}
        onClose={() => setAssigning(null)}
      />
    </RoleGate>
  );
}

function RotationAssignDialog({
  rotation,
  onClose,
}: {
  rotation: ShiftRotation | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const [type, setType] = useState<"employee" | "department" | "branch">(
    "employee",
  );
  const [targetId, setTargetId] = useState("");
  const [effectiveFrom, setEffectiveFrom] = useState(todayIso());
  const [effectiveTo, setEffectiveTo] = useState("");
  const [anchorDate, setAnchorDate] = useState("");

  const assign = useAssignShiftRotation();

  // Only the list actually being chosen from is fetched, matching the shift
  // assignments page — three eager lists would be three requests for two the
  // user will never open.
  const { data: employees } = useQuery({
    queryKey: ["employees", "list"],
    queryFn: async () => (await apiClient.get("/employees?per_page=200")).data,
    enabled: rotation !== null && type === "employee",
  });
  const { data: departments } = useQuery({
    queryKey: ["departments"],
    queryFn: async () =>
      (await apiClient.get("/organization/departments?per_page=100")).data,
    enabled: rotation !== null && type === "department",
  });
  const { data: branches } = useQuery({
    queryKey: ["branches"],
    queryFn: async () =>
      (await apiClient.get("/organization/branches?per_page=100")).data,
    enabled: rotation !== null && type === "branch",
  });

  const options: { public_id: string; name: string }[] =
    type === "employee"
      ? (employees?.data ?? [])
      : type === "department"
        ? (departments?.data ?? [])
        : (branches?.data ?? []);

  async function submit() {
    if (!rotation) return;

    try {
      await assign.mutateAsync({
        rotation_id: rotation.public_id,
        assignable_type: type,
        assignable_id: targetId,
        effective_from: effectiveFrom,
        effective_to: effectiveTo || null,
        // Omitted rather than sent equal to effective_from: the API already
        // defaults it, and sending it explicitly would hide a later change to
        // that default.
        anchor_date: anchorDate || null,
      });
      toast.success(t("rotations_page.assigned", "Rotation assigned"));
      setTargetId("");
      setAnchorDate("");
      onClose();
    } catch (err: unknown) {
      const e = err as {
        response?: {
          data?: { detail?: string; errors?: Record<string, string[]> };
        };
      };
      toast.error(
        e.response?.data?.detail ??
          Object.values(e.response?.data?.errors ?? {})[0]?.[0] ??
          t("rotations_page.assign_failed", "Could not assign rotation"),
      );
    }
  }

  return (
    <Dialog open={rotation !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {t("rotations_page.assign_title", "Assign rotation")}
            {rotation ? ` — ${rotation.name}` : ""}
          </DialogTitle>
          <DialogDescription>
            {t(
              "rotations_page.assign_description",
              "Put an employee, department or branch onto this pattern.",
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="assign-type">
                {t("rotations_page.assign_to", "Assign to")}
              </Label>
              <Select
                value={type}
                onValueChange={(v) => {
                  setType(v as typeof type);
                  // The previous selection belongs to a different list.
                  setTargetId("");
                }}
              >
                <SelectTrigger id="assign-type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="employee">
                    {t("shifts_settings_page.employee", "Employee")}
                  </SelectItem>
                  <SelectItem value="department">
                    {t("shifts_settings_page.department", "Department")}
                  </SelectItem>
                  <SelectItem value="branch">
                    {t("shifts_settings_page.branch", "Branch")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="assign-target">
                {t("rotations_page.target", "Who")}
              </Label>
              <Select value={targetId} onValueChange={setTargetId}>
                <SelectTrigger id="assign-target">
                  <SelectValue placeholder={t("common.select", "Select...")} />
                </SelectTrigger>
                <SelectContent>
                  {options.map((o) => (
                    <SelectItem key={o.public_id} value={o.public_id}>
                      {o.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="assign-from">
                {t("rotations_page.effective_from", "Effective from")}
              </Label>
              <DualCalendarDateInput
                id="assign-from"
                value={effectiveFrom}
                onChange={setEffectiveFrom}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="assign-to">
                {t("rotations_page.effective_to", "Effective to (optional)")}
              </Label>
              <DualCalendarDateInput
                id="assign-to"
                value={effectiveTo}
                onChange={setEffectiveTo}
              />
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="assign-anchor">
              {t("rotations_page.anchor_date", "Cycle start (optional)")}
            </Label>
            <DualCalendarDateInput
              id="assign-anchor"
              value={anchorDate}
              onChange={setAnchorDate}
            />
            <p className="text-xs text-muted-foreground">
              {t(
                "rotations_page.anchor_hint",
                "Which date counts as day 1 of the pattern. Leave empty to start at the effective date — set it only when someone joins a team already part-way through its cycle.",
              )}
            </p>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel", "Cancel")}
          </Button>
          <Button
            onClick={submit}
            disabled={assign.isPending || !targetId || !effectiveFrom}
          >
            {assign.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("rotations_page.assign", "Assign")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function RotationPreviewDialog({
  rotation,
  onClose,
}: {
  rotation: ShiftRotation | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const [anchor, setAnchor] = useState(todayIso());

  // Two cycles, so the repeat itself is visible rather than inferred.
  const span = rotation ? Math.min(rotation.cycle_days * 2, 60) : 14;
  const preview = useRotationPreview(
    rotation?.public_id ?? "",
    { from: anchor, to: addDaysIso(anchor, span - 1), anchor_date: anchor },
    rotation !== null,
  );

  return (
    <Dialog open={rotation !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>
            {t("rotations_page.preview_title", "Preview")}
            {rotation ? ` — ${rotation.name}` : ""}
          </DialogTitle>
          <DialogDescription>
            {t(
              "rotations_page.preview_description",
              "The resolved pattern, calculated by the server so it matches what attendance will apply.",
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-2">
          <Label htmlFor="preview-anchor">
            {t("rotations_page.start_date", "Start date")}
          </Label>
          <DualCalendarDateInput
            id="preview-anchor"
            value={anchor}
            onChange={setAnchor}
          />
        </div>

        {rotation && (
          <QueryBoundary query={preview}>
            {(data) => (
              <SimpleTable
                caption={t("rotations_page.preview_title", "Preview")}
                headers={[
                  t("rotations_page.date", "Date"),
                  t("rotations_page.shift", "Shift"),
                ]}
                maxHeight="45vh"
                rows={data.days.map((day) => ({
                  key: day.date,
                  className: day.is_rest_day ? "bg-muted/40" : undefined,
                  cells: [
                    day.date,
                    day.is_rest_day ? (
                      <span
                        key="r"
                        className="flex items-center gap-1 text-muted-foreground"
                      >
                        <Moon className="h-3 w-3" aria-hidden="true" />
                        {t("rotations_page.rest", "Rest")}
                      </span>
                    ) : (
                      <span key="s">
                        {day.shift?.name}{" "}
                        <span className="text-xs text-muted-foreground">
                          {day.shift?.start_time}–{day.shift?.end_time}
                        </span>
                      </span>
                    ),
                  ],
                }))}
              />
            )}
          </QueryBoundary>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.close", "Close")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
