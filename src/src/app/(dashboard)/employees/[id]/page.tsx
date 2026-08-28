"use client";

import { use, useState } from "react";
import Link from "next/link";
import {
  ArrowLeft,
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
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { StatusBadge } from "@/components/shared/status-badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { useEmployee, useUpdateEmployee } from "@/features/employees/api";
import { EmployeeHistoryTab } from "@/features/employees/components/employee-history-tab";
import { DisciplinaryCasesTab } from "@/features/employees/components/disciplinary-cases-tab";
import { ContractsTab } from "@/features/employees/components/contracts-tab";
import { RetirementTab } from "@/features/employees/components/retirement-tab";
import { EducationTab } from "@/features/employees/components/education-tab";
import { DocumentsTab } from "@/features/employees/components/documents-tab";
import { BankDetailsTab } from "@/features/employees/components/bank-details-tab";
import { EmergencyContactsTab } from "@/features/employees/components/emergency-contacts-tab";
import { AttendanceTimelineTab } from "@/features/employees/components/attendance-timeline-tab";
import { LifecycleTab } from "@/features/employees/components/lifecycle-tab";
import { InfoRow } from "@/features/employees/components/info-row";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

export default function EmployeeDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const { t } = useT();
  const { data: employee, isLoading } = useEmployee(id);
  const updateEmployee = useUpdateEmployee(id);
  const [editing, setEditing] = useState(false);
  const [editForm, setEditForm] = useState<Record<string, string>>({});

  function startEdit() {
    if (!employee) return;
    setEditForm({
      name: employee.name ?? "",
      email: employee.email ?? "",
      phone: employee.phone ?? "",
    });
    setEditing(true);
  }

  function handleSave() {
    updateEmployee.mutate(editForm, {
      onSuccess: () => {
        toast.success(t("employee.detail.updated", "Employee updated"));
        setEditing(false);
      },
      onError: () =>
        toast.error(
          t("employee.detail.update_failed", "Failed to update employee"),
        ),
    });
  }

  if (isLoading) {
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
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" asChild>
          <Link href="/employees">
            <ArrowLeft className="mr-2 h-4 w-4" />
            {t("common.back", "Back")}
          </Link>
        </Button>
      </div>

      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex items-center gap-4">
          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
            <span className="text-lg font-bold text-primary">
              {employee.name
                .split(" ")
                .map((n) => n[0])
                .join("")
                .slice(0, 2)
                .toUpperCase()}
            </span>
          </div>
          <div>
            <h1 className="text-2xl font-bold text-foreground">
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
            <Button variant="outline" size="sm" onClick={startEdit}>
              <Pencil className="mr-2 h-3 w-3" />
              {t("common.edit", "Edit")}
            </Button>
          )}
        </div>
      </div>

      <Tabs defaultValue="info" className="w-full">
        <TabsList>
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
                    >
                      <X className="h-3 w-3" />
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

        <TabsContent value="attendance" className="mt-4">
          <AttendanceTimelineTab employeeId={id} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
