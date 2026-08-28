"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { CalendarRange, Plus, Users, Building2, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface AssignmentForm {
  shift_public_id: string;
  assignable_type: "employee" | "department" | "branch";
  assignable_public_id: string;
  effective_from: string;
  effective_to: string;
}

const EMPTY_FORM: AssignmentForm = {
  shift_public_id: "",
  assignable_type: "employee",
  assignable_public_id: "",
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: "",
};

const TYPE_ICONS = {
  employee: Users,
  department: Building2,
  branch: Building2,
};

function AssignmentsContent() {
  const { t } = useT();
  const TYPE_LABEL: Record<string, string> = {
    Employee: t("shifts_settings_page.employee"),
    Department: t("shifts_settings_page.department"),
    Branch: t("shifts_settings_page.branch"),
  };
  const qc = useQueryClient();
  const searchParams = useSearchParams();
  const preselectedShift = searchParams.get("shift") ?? "";

  const [showDialog, setShowDialog] = useState(!!preselectedShift);
  const [form, setForm] = useState<AssignmentForm>({
    ...EMPTY_FORM,
    shift_public_id: preselectedShift,
  });

  const { data: shifts } = useQuery({
    queryKey: ["shifts"],
    queryFn: async () => (await apiClient.get("/shifts?per_page=100")).data,
  });

  const { data: schedule, isLoading } = useQuery({
    queryKey: ["shifts", "schedule"],
    queryFn: async () =>
      (await apiClient.get("/shifts/schedule?per_page=100")).data,
  });

  const { data: employees } = useQuery({
    queryKey: ["employees", "list"],
    queryFn: async () => (await apiClient.get("/employees?per_page=200")).data,
    enabled: form.assignable_type === "employee",
  });

  const { data: departments } = useQuery({
    queryKey: ["departments"],
    queryFn: async () =>
      (await apiClient.get("/organization/departments?per_page=100")).data,
    enabled: form.assignable_type === "department",
  });

  const { data: branches } = useQuery({
    queryKey: ["branches"],
    queryFn: async () =>
      (await apiClient.get("/organization/branches?per_page=100")).data,
    enabled: form.assignable_type === "branch",
  });

  const assign = useMutation({
    mutationFn: async (payload: AssignmentForm) => {
      const body: Record<string, unknown> = {
        shift_public_id: payload.shift_public_id,
        assignable_type: payload.assignable_type,
        assignable_public_id: payload.assignable_public_id,
        effective_from: payload.effective_from,
      };
      if (payload.effective_to) body.effective_to = payload.effective_to;
      return (await apiClient.post("/shifts/assign", body)).data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["shifts", "schedule"] });
      qc.invalidateQueries({ queryKey: ["shifts"] });
      setShowDialog(false);
      setForm(EMPTY_FORM);
      toast.success(t("shift_assignments_page.assigned_success"));
    },
    onError: (err: unknown) => {
      const e = err as {
        response?: {
          data?: { detail?: string; errors?: Record<string, string[]> };
        };
      };
      const msg =
        e.response?.data?.detail ??
        Object.values(e.response?.data?.errors ?? {})[0]?.[0] ??
        t("shifts_settings_page.assign_failed");
      toast.error(msg);
    },
  });

  const assignments = schedule?.data ?? [];
  const shiftList = shifts?.data ?? [];

  // Assignable options based on type
  const assignableOptions = (() => {
    if (form.assignable_type === "employee")
      return (employees?.data ?? []) as { public_id: string; name: string }[];
    if (form.assignable_type === "department")
      return (departments?.data ?? []) as { public_id: string; name: string }[];
    return (branches?.data ?? []) as { public_id: string; name: string }[];
  })();

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("shift_assignments_page.title")}
        description={t("shift_assignments_page.description")}
        actions={
          <Button
            onClick={() => {
              setForm(EMPTY_FORM);
              setShowDialog(true);
            }}
          >
            <Plus className="mr-2 h-4 w-4" />{" "}
            {t("shifts_settings_page.assign_shift")}
          </Button>
        }
      />

      {/* Current assignments */}
      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : assignments.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16 text-center">
            <CalendarRange className="h-12 w-12 text-muted-foreground/40" />
            <p className="mt-3 font-medium">
              {t("shift_assignments_page.no_assignments")}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              {t("shift_assignments_page.no_assignments_desc")}
            </p>
            <Button
              className="mt-4"
              onClick={() => {
                setForm(EMPTY_FORM);
                setShowDialog(true);
              }}
            >
              <Plus className="mr-2 h-4 w-4" />{" "}
              {t("shift_assignments_page.assign_first")}
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {assignments.map(
            (
              a: {
                shift?: { name: string; start_time: string; end_time: string };
                assignable_type: string;
                effective_from: string;
                effective_to: string | null;
                created_at: string;
              },
              i: number,
            ) => {
              const Icon =
                TYPE_ICONS[
                  a.assignable_type?.toLowerCase() as keyof typeof TYPE_ICONS
                ] ?? Users;
              const isActive =
                !a.effective_to || new Date(a.effective_to) >= new Date();
              return (
                <Card key={i}>
                  <CardContent className="flex items-center gap-4 p-4">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                      <Icon className="h-4 w-4 text-primary" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="font-medium">
                          {a.shift?.name ??
                            t("shift_assignments_page.unknown_shift")}
                        </p>
                        <Badge variant="outline" className="text-xs">
                          {TYPE_LABEL[a.assignable_type] ?? a.assignable_type}
                        </Badge>
                        <Badge
                          variant={isActive ? "success" : "secondary"}
                          className="text-xs"
                        >
                          {isActive
                            ? t("webhooks_page.active")
                            : t("shift_assignments_page.expired")}
                        </Badge>
                      </div>
                      <p className="mt-0.5 text-sm text-muted-foreground">
                        {a.shift?.start_time} – {a.shift?.end_time}
                        {" · "}
                        {t("shift_assignments_page.from")} {a.effective_from}
                        {a.effective_to
                          ? ` ${t("shift_assignments_page.to_lc")} ${a.effective_to}`
                          : ` (${t("shift_assignments_page.no_end_date")})`}
                      </p>
                    </div>
                  </CardContent>
                </Card>
              );
            },
          )}
        </div>
      )}

      {/* Assign Dialog */}
      <Dialog open={showDialog} onOpenChange={setShowDialog}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{t("shifts_settings_page.assign_shift")}</DialogTitle>
            <DialogDescription>
              {t("shift_assignments_page.assign_desc")}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {/* Shift */}
            <div>
              <Label htmlFor="shift-required">
                {t("shift_assignments_page.shift_required")}
              </Label>
              <Select
                value={form.shift_public_id}
                onValueChange={(v) =>
                  setForm((f) => ({ ...f, shift_public_id: v }))
                }
              >
                <SelectTrigger id="shift-required" className="mt-1">
                  <SelectValue
                    placeholder={t("shifts_settings_page.select_shift")}
                  />
                </SelectTrigger>
                <SelectContent>
                  {shiftList.map(
                    (s: {
                      public_id: string;
                      name: string;
                      start_time: string;
                      end_time: string;
                    }) => (
                      <SelectItem key={s.public_id} value={s.public_id}>
                        {s.name} ({s.start_time}–{s.end_time})
                      </SelectItem>
                    ),
                  )}
                </SelectContent>
              </Select>
            </div>

            {/* Assignable type */}
            <div>
              <Label>{t("shift_assignments_page.assign_to_required")}</Label>
              <Select
                value={form.assignable_type}
                onValueChange={(v) =>
                  setForm((f) => ({
                    ...f,
                    assignable_type: v as AssignmentForm["assignable_type"],
                    assignable_public_id: "",
                  }))
                }
              >
                <SelectTrigger className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="employee">
                    {t("shift_assignments_page.employee_highest")}
                  </SelectItem>
                  <SelectItem value="department">
                    {t("shifts_settings_page.department")}
                  </SelectItem>
                  <SelectItem value="branch">
                    {t("shift_assignments_page.branch_lowest")}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p className="mt-1 text-xs text-muted-foreground">
                {t("shift_assignments_page.priority_hint")}
              </p>
            </div>

            {/* Assignable entity */}
            <div>
              <Label>
                {form.assignable_type === "employee"
                  ? t("shifts_settings_page.employee")
                  : form.assignable_type === "department"
                    ? t("shifts_settings_page.department")
                    : t("shifts_settings_page.branch")}{" "}
                *
              </Label>
              <Select
                value={form.assignable_public_id}
                onValueChange={(v) =>
                  setForm((f) => ({ ...f, assignable_public_id: v }))
                }
              >
                <SelectTrigger className="mt-1">
                  <SelectValue
                    placeholder={`${t("shift_assignments_page.select_prefix")} ${form.assignable_type}`}
                  />
                </SelectTrigger>
                <SelectContent>
                  {assignableOptions.map((o) => (
                    <SelectItem key={o.public_id} value={o.public_id}>
                      {o.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Date range */}
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>{t("shifts_settings_page.effective_from")} *</Label>
                <DualCalendarDateInput
                  value={form.effective_from}
                  onChange={(v) =>
                    setForm((f) => ({ ...f, effective_from: v }))
                  }
                  className="mt-1"
                />
              </div>
              <div>
                <Label>{t("shifts_settings_page.effective_to")}</Label>
                <DualCalendarDateInput
                  value={form.effective_to}
                  onChange={(v) => setForm((f) => ({ ...f, effective_to: v }))}
                  min={form.effective_from}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  {t("shift_assignments_page.leave_blank_hint")}
                </p>
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDialog(false)}>
              {t("common.cancel")}
            </Button>
            <Button
              onClick={() => assign.mutate(form)}
              disabled={
                !form.shift_public_id ||
                !form.assignable_public_id ||
                !form.effective_from ||
                assign.isPending
              }
            >
              {assign.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("shifts_settings_page.assign_shift")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

export default function ShiftAssignmentsPage() {
  return (
    <RoleGate minRole="hr_admin">
      <Suspense fallback={<Skeleton className="h-64" />}>
        <AssignmentsContent />
      </Suspense>
    </RoleGate>
  );
}
