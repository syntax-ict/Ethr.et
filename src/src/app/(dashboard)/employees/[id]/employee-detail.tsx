"use client";

import {
  NationalityStatusSelect,
  useNationalityStatusLabel,
} from "@/features/employees/components/nationality-status-select";
import { useState } from "react";
import Link from "next/link";
import {
  Fingerprint,
  Mail,
  Phone,
  Calendar,
  Building2,
  Briefcase,
  Pencil,
  Save,
  X,
  Loader2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useEntityTitle } from "@/components/layouts/page-actions-slot";
import { StatusBadge } from "@/components/shared/status-badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { useEmployee, useUpdateEmployee } from "@/features/employees/api";
import { EmployeeHistoryTab } from "@/features/employees/components/employee-history-tab";
import { DisciplinaryCasesTab } from "@/features/employees/components/disciplinary-cases-tab";
import { ContractsTab } from "@/features/employees/components/contracts-tab";
import { RetirementTab } from "@/features/employees/components/retirement-tab";
import { ExitClearanceTab } from "@/features/offboarding/components/exit-clearance-tab";
import { EducationTab } from "@/features/employees/components/education-tab";
import { DocumentsTab } from "@/features/employees/components/documents-tab";
import { BankDetailsTab } from "@/features/employees/components/bank-details-tab";
import { EmergencyContactsTab } from "@/features/employees/components/emergency-contacts-tab";
import { AttendanceTimelineTab } from "@/features/employees/components/attendance-timeline-tab";
import { LifecycleTab } from "@/features/employees/components/lifecycle-tab";
import { InfoRow } from "@/features/employees/components/info-row";
import { useRouteId } from "@/lib/hooks/useRouteId";
import { useT } from "@/lib/i18n/useT";
import { useQueryTab } from "@/lib/hooks/useQueryTab";
import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";
import { AttendanceModeCard } from "@/features/employees/components/attendance-mode-card";
import { KioskPinCard } from "@/features/employees/components/kiosk-pin-card";
import { InviteToEthrButton } from "@/features/employees/components/invite-to-ethr-button";
import { EnrolOnDevicesButton } from "@/features/devices/components/enrol-on-devices-button";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useEmployeeOptions } from "@/features/attendance/api";
import { costCentersApi, teamsApi } from "@/features/organization/api";
import { RelationPicker } from "@/features/employees/components/relation-picker";
import { ErrorState } from "@/components/shared/error-state";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";

/** A 404 means the record is gone; anything else means the request failed. */
function isNotFound(error: unknown): boolean {
  return (
    (error as { response?: { status?: number } } | null)?.response?.status ===
    404
  );
}

const EMPLOYEE_TABS = [
  "info",
  "employment",
  "documents",
  "bank",
  "emergency",
  "education",
  "lifecycle",
  "contracts",
  "history",
  "discipline",
  "retirement",
  "exit",
  "attendance",
] as const;

/** Resigned, terminated or retired: the statuses with an exit clearance. */
const LEAVING_STATUSES = ["resigned", "terminated", "retired"];

export function EmployeeDetail({ routeId }: { routeId: string }) {
  // `?tab=attendance` is how search opens this record on its attendance.
  const [tab, setTab] = useQueryTab(EMPLOYEE_TABS, "info");
  const id = useRouteId(routeId) ?? "";
  const { t } = useT();
  const {
    data: employee,
    isLoading,
    isError,
    error,
    refetch,
  } = useEmployee(id);
  const nationalityLabel = useNationalityStatusLabel();
  useEntityTitle(employee?.name);
  const canManageAttendance =
    usePermissions().hasPermission("attendance.manage");
  const [editing, setEditing] = useState(false);
  const updateEmployee = useUpdateEmployee(id);
  // Options load only once editing starts.
  const { data: employeeOptions } = useEmployeeOptions({ enabled: editing });
  const { data: teams } = teamsApi.useList();
  const { data: costCenters } = costCentersApi.useList();
  const [editForm, setEditForm] = useState<Record<string, string>>({});

  function startEdit() {
    if (!employee) return;
    setEditForm({
      name: employee.name ?? "",
      email: employee.email ?? "",
      phone: employee.phone ?? "",
      // The id terminals know the person by; a change goes to the branch's
      // devices that take commands (DeviceCommandQueue).
      badge_number: employee.badge_number ?? "",
      // Decides pension coverage; saving it clears a "check nationality" flag.
      nationality_status: employee.nationality_status ?? "ethiopian",
      // Settable nowhere else: no screen assigned a supervisor, team or cost
      // centre, so the reporting chart was flat (audit N73). Empty clears it.
      supervisor_id: employee.supervisor?.public_id ?? "",
      team_id: employee.team?.public_id ?? "",
      cost_center_id: employee.cost_center?.public_id ?? "",
    });
    setEditing(true);
    // The form lives on the Info tab; from any other tab Edit appeared to
    // do nothing (FRONTEND_REDESIGN_PLAN.md, employee detail).
    setTab("info");
  }

  function handleSave() {
    updateEmployee.mutate(editForm, {
      onSuccess: () => {
        toast.success(t("employee.detail.updated", "Employee updated"));
        setEditing(false);
      },
      // The server's reason, so a badge already taken says so.
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("employee.detail.update_failed", "Failed to update employee"),
          ),
        ),
    });
  }

  if (!id || isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <div className="grid gap-6 md:grid-cols-2">
          <Skeleton className="h-64" />
          <Skeleton className="h-64" />
        </div>
      </div>
    );
  }

  // Every failure used to read "Employee not found", which sent people to
  // look for a record that was there all along.
  if (isError && !isNotFound(error)) {
    return <ErrorState onRetry={() => refetch()} />;
  }

  if (!employee) {
    return (
      <div className="flex flex-col items-center justify-center py-20">
        <p className="text-lg font-medium text-foreground">
          {t("employee.detail.not_found", "Employee not found")}
        </p>
        <Button variant="outline" className="mt-4" asChild>
          <Link href="/employees">
            {t("employee.detail.back_to_list", "Back to list")}
          </Link>
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex items-center gap-4">
          {/* The shared avatar shows the employee's photo; this drew initials
              by hand, so a photo uploaded on the profile never appeared. */}
          <EmployeeAvatar
            name={employee.name}
            photoThumbUrl={employee.photo_thumb_url}
            photoUrl={employee.photo_url}
            className="h-14 w-14 shrink-0 ring-1 ring-border"
            fallbackClassName="bg-primary-soft text-lg font-semibold text-primary-on-soft"
          />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight text-foreground">
              {employee.name}
            </h1>
            <p className="text-sm text-muted-foreground">
              {employee.employee_code}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <StatusBadge status={employee.status} />
          {!editing && (
            <InviteToEthrButton
              employeeId={employee.public_id}
              email={employee.email}
            />
          )}
          {!editing && <EnrolOnDevicesButton employeeId={employee.public_id} />}
          {!editing && (
            <Button variant="outline" size="sm" onClick={startEdit}>
              <Pencil className="mr-2 h-3 w-3" />
              {t("common.edit", "Edit")}
            </Button>
          )}
        </div>
      </div>

      <Tabs value={tab} onValueChange={setTab} className="w-full">
        <TabsList variant="underline">
          <TabsTrigger value="info">
            {t("employee.detail.tab.info", "Information")}
          </TabsTrigger>
          <TabsTrigger value="employment">
            {t("employee.detail.tab.employment", "Employment")}
          </TabsTrigger>
          <TabsTrigger value="documents">
            {t("employee.detail.tab.documents", "Documents")}
          </TabsTrigger>
          <TabsTrigger value="bank">
            {t("employee.detail.tab.bank", "Bank Details")}
          </TabsTrigger>
          <TabsTrigger value="emergency">
            {t("employee.detail.tab.emergency", "Emergency Contacts")}
          </TabsTrigger>
          <TabsTrigger value="education">
            {t("employee.detail.tab.education", "Education")}
          </TabsTrigger>
          <TabsTrigger value="lifecycle">
            {t("employee.detail.tab.lifecycle", "Lifecycle")}
          </TabsTrigger>
          <TabsTrigger value="contracts">
            {t("employee.detail.tab.contracts", "Contracts")}
          </TabsTrigger>
          <TabsTrigger value="history">
            {t("employee.detail.tab.history", "History")}
          </TabsTrigger>
          <TabsTrigger value="discipline">
            {t("employee.detail.tab.discipline", "Discipline")}
          </TabsTrigger>
          <TabsTrigger value="retirement">
            {t("employee.detail.tab.retirement", "Retirement")}
          </TabsTrigger>
          {LEAVING_STATUSES.includes(employee.status ?? "") && (
            <TabsTrigger value="exit">
              {t("employee.detail.tab.exit", "Exit clearance")}
            </TabsTrigger>
          )}
          <TabsTrigger value="attendance">
            {t("employee.detail.tab.attendance", "Attendance")}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="info" className="mt-4">
          <div className="grid gap-6 md:grid-cols-2">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-base">
                  {t("employee.detail.personal_info", "Personal Information")}
                </CardTitle>
                {editing && (
                  <div className="flex gap-1">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => setEditing(false)}
                      aria-label={t("common.cancel", "Cancel")}
                    >
                      <X className="h-3 w-3" aria-hidden="true" />
                    </Button>
                    <Button
                      size="sm"
                      onClick={handleSave}
                      disabled={updateEmployee.isPending}
                    >
                      {updateEmployee.isPending ? (
                        <Loader2 className="h-3 w-3 animate-spin" />
                      ) : (
                        <Save className="mr-1 h-3 w-3" />
                      )}
                      {t("common.save", "Save")}
                    </Button>
                  </div>
                )}
              </CardHeader>
              <CardContent className="space-y-4">
                {editing ? (
                  <>
                    <div>
                      <Label>{t("common.name", "Name")}</Label>
                      <Input
                        value={editForm.name}
                        onChange={(e) =>
                          setEditForm((p) => ({ ...p, name: e.target.value }))
                        }
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label>{t("common.email", "Email")}</Label>
                      <Input
                        type="email"
                        value={editForm.email}
                        onChange={(e) =>
                          setEditForm((p) => ({ ...p, email: e.target.value }))
                        }
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label>{t("common.phone", "Phone")}</Label>
                      <Input
                        value={editForm.phone}
                        onChange={(e) =>
                          setEditForm((p) => ({ ...p, phone: e.target.value }))
                        }
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label htmlFor="edit-badge">
                        {t("employee.badge_number")}
                      </Label>
                      <Input
                        id="edit-badge"
                        value={editForm.badge_number}
                        maxLength={32}
                        onChange={(e) =>
                          setEditForm((p) => ({
                            ...p,
                            badge_number: e.target.value,
                          }))
                        }
                        className="mt-1 font-mono"
                      />
                      <p className="mt-1 text-xs text-muted-foreground">
                        {t("employee.badge_number_hint")}
                      </p>
                    </div>
                    <div>
                      <Label htmlFor="edit-nationality-status">
                        {t("employee.nationality_status")}
                      </Label>
                      <NationalityStatusSelect
                        id="edit-nationality-status"
                        value={editForm.nationality_status}
                        onChange={(v) =>
                          setEditForm((p) => ({ ...p, nationality_status: v }))
                        }
                      />
                      <p className="mt-1 text-xs text-muted-foreground">
                        {t("employee.nationality_status_hint")}
                      </p>
                    </div>
                    <RelationPicker
                      id="edit-supervisor"
                      label={t("employee.supervisor", "Supervisor")}
                      value={editForm.supervisor_id}
                      options={(employeeOptions ?? [])
                        .filter((e) => e.public_id !== employee.public_id)
                        .map((e) => ({ value: e.public_id, label: e.name }))}
                      onChange={(v) =>
                        setEditForm((p) => ({ ...p, supervisor_id: v }))
                      }
                    />
                    <RelationPicker
                      id="edit-team"
                      label={t("employee.team", "Team")}
                      value={editForm.team_id}
                      options={(teams?.data ?? []).map((x) => ({
                        value: x.public_id,
                        label: x.name,
                      }))}
                      onChange={(v) =>
                        setEditForm((p) => ({ ...p, team_id: v }))
                      }
                    />
                    <RelationPicker
                      id="edit-cost-center"
                      label={t("employee.cost_center", "Cost centre")}
                      value={editForm.cost_center_id}
                      options={(costCenters?.data ?? []).map((x) => ({
                        value: x.public_id,
                        label: x.name,
                      }))}
                      onChange={(v) =>
                        setEditForm((p) => ({ ...p, cost_center_id: v }))
                      }
                    />
                  </>
                ) : (
                  <>
                    <InfoRow
                      icon={Mail}
                      label={t("common.email", "Email")}
                      value={employee.email ?? "—"}
                    />
                    <InfoRow
                      icon={Phone}
                      label={t("common.phone", "Phone")}
                      value={employee.phone ?? "—"}
                    />
                    <InfoRow
                      icon={Calendar}
                      label={t("employee.detail.hire_date", "Hire Date")}
                      value={employee.hire_date ?? "—"}
                    />
                    <InfoRow
                      icon={Fingerprint}
                      label={t("employee.badge_number")}
                      value={employee.badge_number ?? "—"}
                    />
                    <InfoRow
                      label={t("employee.nationality_status")}
                      value={
                        <span className="inline-flex flex-wrap items-center gap-2">
                          {nationalityLabel(employee.nationality_status)}
                          {employee.nationality_needs_review && (
                            <Badge className="border-0 bg-warning-soft text-warning-on-soft">
                              {t("employee.nationality_needs_review")}
                            </Badge>
                          )}
                        </span>
                      }
                    />
                    {employee.gender && (
                      <InfoRow
                        label={t("employee.detail.gender", "Gender")}
                        value={employee.gender}
                      />
                    )}
                  </>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("employee.detail.organization", "Organization")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <InfoRow
                  icon={Building2}
                  label={t("common.department", "Department")}
                  value={employee.department?.name ?? "—"}
                />
                <InfoRow
                  icon={Briefcase}
                  label={t("common.position", "Position")}
                  value={employee.position?.title ?? "—"}
                />
                <InfoRow
                  label={t("employee.supervisor", "Supervisor")}
                  value={employee.supervisor?.name ?? "—"}
                />
                <InfoRow
                  label={t("employee.team", "Team")}
                  value={employee.team?.name ?? "—"}
                />
                <InfoRow
                  label={t("employee.cost_center", "Cost centre")}
                  value={employee.cost_center?.name ?? "—"}
                />
                <InfoRow
                  label={t("employee.detail.branch", "Branch")}
                  value={employee.branch?.name ?? "—"}
                />
                {employee.salary_cents !== undefined && (
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">
                      {t("employee.detail.salary", "Salary")}
                    </span>
                    <CurrencyDisplay
                      cents={employee.salary_cents}
                      className="text-sm font-medium"
                    />
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="employment" className="mt-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t(
                  "employee.detail.employment_timeline",
                  "Employment Timeline",
                )}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="flex items-center gap-4">
                  <div className="h-3 w-3 rounded-full bg-success" />
                  <div>
                    <p className="text-sm font-medium">
                      {t("employee.detail.hired", "Hired")}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {employee.hire_date}
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-4">
                  <div className="h-3 w-3 rounded-full bg-primary" />
                  <div>
                    <p className="text-sm font-medium">
                      {t("employee.detail.current_status", "Current Status")}
                    </p>
                    <StatusBadge status={employee.status} />
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="documents" className="mt-4">
          <DocumentsTab employeeId={id} />
        </TabsContent>

        <TabsContent value="bank" className="mt-4">
          <BankDetailsTab employeeId={id} />
        </TabsContent>

        <TabsContent value="emergency" className="mt-4">
          <EmergencyContactsTab employeeId={id} />
        </TabsContent>

        <TabsContent value="education" className="mt-4">
          <EducationTab employeeId={id} />
        </TabsContent>

        <TabsContent value="lifecycle" className="mt-4">
          <LifecycleTab employeeId={id} currentStatus={employee.status} />
        </TabsContent>

        <TabsContent value="contracts" className="mt-4">
          <ContractsTab employeeId={id} />
        </TabsContent>

        <TabsContent value="history" className="mt-4">
          <EmployeeHistoryTab employeeId={id} />
        </TabsContent>

        <TabsContent value="discipline" className="mt-4">
          <DisciplinaryCasesTab employeeId={id} />
        </TabsContent>

        <TabsContent value="retirement" className="mt-4">
          <RetirementTab employeeId={id} />
        </TabsContent>

        <TabsContent value="exit" className="mt-4">
          <ExitClearanceTab employeeId={id} />
        </TabsContent>

        <TabsContent value="attendance" className="mt-4 space-y-4">
          {canManageAttendance && <AttendanceModeCard employee={employee} />}
          {canManageAttendance && <KioskPinCard employee={employee} />}
          <AttendanceTimelineTab employeeId={id} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
