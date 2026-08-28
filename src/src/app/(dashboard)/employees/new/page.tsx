"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { ArrowLeft, Loader2 } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useCreateEmployee } from "@/features/employees/api";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

import { useUnsavedChangesWarning } from "@/lib/hooks/useUnsavedChangesWarning";
import { FormField } from "@/components/patterns/FormField";
import { fieldErrors, toastError, type FieldErrors } from "@/lib/errors";
import { toast } from "sonner";

export default function NewEmployeePage() {
  const { t } = useT();
  const router = useRouter();
  const createEmployee = useCreateEmployee();

  const { data: depts } = useQuery({
    queryKey: ["org", "departments"],
    queryFn: async () => {
      const { data } = await apiClient.get("/organization/departments");
      return data;
    },
  });
  const { data: branches } = useQuery({
    queryKey: ["org", "branches"],
    queryFn: async () => {
      const { data } = await apiClient.get("/organization/branches");
      return data;
    },
  });
  const { data: positions } = useQuery({
    queryKey: ["org", "positions"],
    queryFn: async () => {
      const { data } = await apiClient.get("/organization/positions");
      return data;
    },
  });

  const [form, setForm] = useState({
    name: "",
    email: "",
    phone: "",
    employee_code: "",
    gender: "",
    date_of_birth: "",
    nationality: "Ethiopian",
    national_id: "",
    marital_status: "single",
    hire_date: "",
    salary_cents: "",
    department_public_id: "",
    branch_public_id: "",
    position_public_id: "",
  });

  const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
  useUnsavedChangesWarning(hasUnsavedChanges);

  /** Per-field messages from the API's RFC-7807 `errors` map. */
  const [errors, setErrors] = useState<FieldErrors>({});

  function updateField(field: string, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }));
    setHasUnsavedChanges(true);
    // Clear this field's error as soon as the user edits it — leaving a stale
    // message under a field they have already corrected reads as broken.
    setErrors((prev) => {
      if (!prev[field]) return prev;
      const { [field]: _removed, ...rest } = prev;
      return rest;
    });
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setErrors({});

    createEmployee.mutate(
      {
        ...form,
        salary_cents: parseInt(form.salary_cents) * 100 || 0,
      },
      {
        onSuccess: () => {
          setHasUnsavedChanges(false);
          toast.success(t("employee.created", "Employee created successfully"));
          router.push("/employees");
        },
        onError: (err: unknown) => {
          const fields = fieldErrors(err);
          setErrors(fields);

          // A validation failure now renders under the offending inputs, so the
          // toast would be redundant noise; anything else still needs one.
          toastError(
            err,
            t("employee.create_failed", "Failed to create employee"),
            { skipValidation: true },
          );

          // Move focus to the first invalid control so keyboard and screen
          // reader users are not left at the submit button with the errors
          // scrolled off above them (WCAG 3.3.1).
          const firstField = Object.keys(fields)[0];
          if (firstField) {
            document.getElementById(firstField)?.focus();
          }
        },
      },
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
        <h1 className="text-2xl font-bold text-foreground">
          {t("employee.add", "Add Employee")}
        </h1>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("employee.personal_info", "Personal Information")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {/* Every control's `id` is the API field name so a 422's
                  `errors` key maps straight onto it — that is also what lets
                  the submit handler focus the first invalid field. */}
              <FormField
                id="name"
                label={t("employee.full_name", "Full Name")}
                required
                error={errors.name}
              >
                <Input
                  value={form.name}
                  onChange={(e) => updateField("name", e.target.value)}
                  required
                />
              </FormField>

              <FormField
                id="email"
                label={t("common.email", "Email")}
                required
                error={errors.email}
              >
                <Input
                  type="email"
                  value={form.email}
                  onChange={(e) => updateField("email", e.target.value)}
                  required
                />
              </FormField>

              <FormField
                id="phone"
                label={t("common.phone", "Phone")}
                error={errors.phone}
              >
                <Input
                  value={form.phone}
                  onChange={(e) => updateField("phone", e.target.value)}
                  placeholder="+251..."
                />
              </FormField>

              <FormField
                id="gender"
                label={t("employee.gender", "Gender")}
                error={errors.gender}
              >
                <Select
                  value={form.gender}
                  onValueChange={(v) => updateField("gender", v)}
                >
                  <SelectTrigger id="gender">
                    <SelectValue
                      placeholder={t("employee.select_gender", "Select gender")}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="male">
                      {t("common.male", "Male")}
                    </SelectItem>
                    <SelectItem value="female">
                      {t("common.female", "Female")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <FormField
                id="date_of_birth"
                label={t("employee.date_of_birth", "Date of Birth")}
                error={errors.date_of_birth}
              >
                <DualCalendarDateInput
                  value={form.date_of_birth}
                  onChange={(v) => updateField("date_of_birth", v)}
                />
              </FormField>

              <FormField
                id="marital_status"
                label={t("employee.marital_status", "Marital Status")}
                error={errors.marital_status}
              >
                <Select
                  value={form.marital_status}
                  onValueChange={(v) => updateField("marital_status", v)}
                >
                  <SelectTrigger id="marital_status">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="single">
                      {t("common.single", "Single")}
                    </SelectItem>
                    <SelectItem value="married">
                      {t("common.married", "Married")}
                    </SelectItem>
                    <SelectItem value="divorced">
                      {t("common.divorced", "Divorced")}
                    </SelectItem>
                    <SelectItem value="widowed">
                      {t("common.widowed", "Widowed")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("employee.employment_details", "Employment Details")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <FormField
                id="employee_code"
                label={t("employee.code", "Employee Code")}
                required
                error={errors.employee_code}
              >
                <Input
                  value={form.employee_code}
                  onChange={(e) => updateField("employee_code", e.target.value)}
                  required
                  placeholder="EMP-0001"
                />
              </FormField>

              <FormField
                id="hire_date"
                label={t("employee.hire_date", "Hire Date")}
                required
                error={errors.hire_date}
              >
                <DualCalendarDateInput
                  value={form.hire_date}
                  onChange={(v) => updateField("hire_date", v)}
                  required
                />
              </FormField>

              <FormField
                id="salary_cents"
                label={t("employee.monthly_salary", "Monthly Salary (ETB)")}
                required
                error={errors.salary_cents}
              >
                <Input
                  type="number"
                  value={form.salary_cents}
                  onChange={(e) => updateField("salary_cents", e.target.value)}
                  required
                  placeholder="5000"
                />
              </FormField>

              <FormField
                id="nationality"
                label={t("employee.nationality", "Nationality")}
                error={errors.nationality}
              >
                <Input
                  value={form.nationality}
                  onChange={(e) => updateField("nationality", e.target.value)}
                />
              </FormField>

              <FormField
                id="national_id"
                label={t("employee.national_id", "National ID")}
                error={errors.national_id}
                hint={t(
                  "employee.national_id_hint",
                  "Stored encrypted. Used to prevent duplicate records during import and device sync.",
                )}
              >
                <Input
                  value={form.national_id}
                  onChange={(e) => updateField("national_id", e.target.value)}
                  placeholder="ETH-123-456"
                />
              </FormField>
              {/* These three carried a bare `<Label>` with no `htmlFor` and a
                  trigger with no `id`, so the selects had no accessible name at
                  all. FormField supplies both. */}
              <FormField
                id="department_public_id"
                label={t("common.department", "Department")}
                error={errors.department_public_id}
              >
                <Select
                  value={form.department_public_id}
                  onValueChange={(v) => updateField("department_public_id", v)}
                >
                  <SelectTrigger id="department_public_id">
                    <SelectValue
                      placeholder={t(
                        "employee.select_department",
                        "Select department",
                      )}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {depts?.data?.map(
                      (d: { public_id: string; name: string }) => (
                        <SelectItem key={d.public_id} value={d.public_id}>
                          {d.name}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </FormField>

              <FormField
                id="branch_public_id"
                label={t("common.branch", "Branch")}
                error={errors.branch_public_id}
              >
                <Select
                  value={form.branch_public_id}
                  onValueChange={(v) => updateField("branch_public_id", v)}
                >
                  <SelectTrigger id="branch_public_id">
                    <SelectValue
                      placeholder={t("employee.select_branch", "Select branch")}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {branches?.data?.map(
                      (b: { public_id: string; name: string }) => (
                        <SelectItem key={b.public_id} value={b.public_id}>
                          {b.name}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </FormField>

              <FormField
                id="position_public_id"
                label={t("common.position", "Position")}
                error={errors.position_public_id}
              >
                <Select
                  value={form.position_public_id}
                  onValueChange={(v) => updateField("position_public_id", v)}
                >
                  <SelectTrigger id="position_public_id">
                    <SelectValue
                      placeholder={t(
                        "employee.select_position",
                        "Select position",
                      )}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {positions?.data?.map(
                      (p: { public_id: string; title: string }) => (
                        <SelectItem key={p.public_id} value={p.public_id}>
                          {p.title}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </FormField>
            </CardContent>
          </Card>
        </div>

        <div className="mt-6 flex justify-end gap-3">
          <Button variant="outline" type="button" asChild>
            <Link href="/employees">{t("common.cancel", "Cancel")}</Link>
          </Button>
          <Button type="submit" disabled={createEmployee.isPending}>
            {createEmployee.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("employee.create", "Create Employee")}
          </Button>
        </div>
      </form>
    </div>
  );
}
