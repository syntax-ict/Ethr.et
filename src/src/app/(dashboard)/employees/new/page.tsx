"use client";

import {
  NationalityStatusSelect,
  type NationalityStatus,
} from "@/features/employees/components/nationality-status-select";
import { useRef, useState, type ReactNode } from "react";
import {
  branchesApi,
  departmentsApi,
  positionsApi,
  teamsApi,
  costCentersApi,
} from "@/features/organization/api";
import { useRouter } from "next/navigation";
import Link from "next/link";
import {
  AlertTriangle,
  Briefcase,
  Loader2,
  Mail,
  UserRound,
  Wallet,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { CurrencyInput } from "@/components/shared/currency-input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { PageActions } from "@/components/shared/page-actions";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  useCreateEmployee,
  useNextEmployeeCode,
  type CreateEmployeePayload,
} from "@/features/employees/api";

import { useUnsavedChangesWarning } from "@/lib/hooks/useUnsavedChangesWarning";
import { FormField } from "@/components/patterns/FormField";
import { fieldErrors, toastError, type FieldErrors } from "@/lib/errors";
import { toast } from "sonner";
import { useEmployeeOptions } from "@/features/attendance/api";
import { RelationPicker } from "@/features/employees/components/relation-picker";

const EMPTY_FORM = {
  name: "",
  name_am: "",
  email: "",
  phone: "",
  employee_code: "",
  badge_number: "",
  gender: "",
  date_of_birth: "",
  nationality: "Ethiopian",
  nationality_status: "ethiopian" as NationalityStatus,
  national_id: "",
  tin: "",
  marital_status: "single",
  hire_date: "",
  salary_cents: "",
  // The API's own field names, so a 422's `errors` keys land on these
  // controls. They were `*_public_id`, which StoreEmployeeRequest does not
  // read — the employee was created with no department, branch or position.
  department_id: "",
  branch_id: "",
  position_id: "",
  // No screen set these three, so the reporting chart was flat and teams
  // and cost centres were never assigned (audit N73).
  supervisor_id: "",
  team_id: "",
  cost_center_id: "",
};

type EmployeeForm = typeof EMPTY_FORM;

/**
 * Add an employee (2026-10-15 redesign). Only the name and the hire date are
 * required, as the API has always said: the form also demanded an email, a
 * code and a salary, so an employee with no email could not be added at all.
 * A blank code takes the organisation's next number. Grouped by what the
 * person filling it in knows at once (who, how to reach them, the job, the
 * pay), with the actions in a bar that stays in reach on a long form.
 */
export default function NewEmployeePage() {
  const { t } = useT();
  const router = useRouter();
  const createEmployee = useCreateEmployee();
  const nextCode = useNextEmployeeCode();

  const { data: depts } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();
  const { data: positions } = positionsApi.useList();
  const { data: teams } = teamsApi.useList();
  const { data: costCenters } = costCentersApi.useList();
  const { data: employeeOptions } = useEmployeeOptions();

  const [form, setForm] = useState<EmployeeForm>(EMPTY_FORM);
  // Bumped by "Save and add another" so every control, including the ones
  // that keep their own text, starts empty.
  const [formKey, setFormKey] = useState(0);
  const afterSave = useRef<"open" | "another">("open");

  // Whether the person gets an ETHR login with this record. The API always
  // supported it (`create_login`) and no screen sent it, so in a new
  // organisation no employee could sign in — nobody could request leave or see
  // a payslip (production QA 2026-10-11, P15). Off by default: on would email
  // every person entered, which an admin keying in records may not intend.
  const [sendLogin, setSendLogin] = useState(false);

  const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
  useUnsavedChangesWarning(hasUnsavedChanges);

  /** Per-field messages from the API's RFC-7807 `errors` map. */
  const [errors, setErrors] = useState<FieldErrors>({});
  const errorCount = Object.keys(errors).length;

  // An organisation with one branch has only one answer; it was a required
  // trip to a dropdown on every employee.
  const branchList = branches?.data ?? [];
  const onlyBranch =
    branchList.length === 1 ? (branchList[0]?.public_id ?? "") : "";
  const branchId = form.branch_id || onlyBranch;

  function updateField(field: keyof EmployeeForm, value: string) {
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

  function startNext(savedName: string) {
    // The next person is usually hired with this one: keep where and when.
    setForm({
      ...EMPTY_FORM,
      hire_date: form.hire_date,
      branch_id: form.branch_id,
      department_id: form.department_id,
      position_id: form.position_id,
    });
    setSendLogin(false);
    setHasUnsavedChanges(false);
    setFormKey((k) => k + 1);
    toast.success(
      t("employee.saved_add_another", "Saved :name. Add the next one.", {
        name: savedName,
      }),
    );
    requestAnimationFrame(() => document.getElementById("name")?.focus());
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setErrors({});

    const payload: CreateEmployeePayload = {
      name: form.name,
      name_am: form.name_am || null,
      email: form.email || null,
      phone: form.phone || null,
      employee_code: form.employee_code.trim() || null,
      badge_number: form.badge_number || null,
      gender: (form.gender || null) as CreateEmployeePayload["gender"],
      date_of_birth: form.date_of_birth || null,
      nationality: form.nationality || null,
      nationality_status: form.nationality_status,
      national_id: form.national_id || null,
      tin: form.tin || null,
      marital_status: (form.marital_status ||
        null) as CreateEmployeePayload["marital_status"],
      hire_date: form.hire_date,
      // Already integer cents — CurrencyInput does the ETB conversion, so
      // this page no longer hand-rolls it (`parseInt` once turned 5000.50
      // into 500000).
      salary_cents: Number(form.salary_cents) || 0,
      department_id: form.department_id || null,
      branch_id: branchId || null,
      position_id: form.position_id || null,
      supervisor_id: form.supervisor_id || null,
      team_id: form.team_id || null,
      cost_center_id: form.cost_center_id || null,
      ...(sendLogin && form.email
        ? { create_login: true, user_role: "employee" as const }
        : {}),
    };

    createEmployee.mutate(payload, {
      onSuccess: (created: {
        public_id?: string;
        name?: string;
        meta?: { login_invited?: boolean };
      }) => {
        setHasUnsavedChanges(false);

        if (afterSave.current === "another") {
          startNext(created?.name ?? form.name);
          return;
        }

        toast.success(
          created?.meta?.login_invited
            ? t(
                "employee.created_and_invited",
                "Employee created, and a login invitation was sent",
              )
            : t("employee.created", "Employee created successfully"),
        );
        // To the record just made, where documents, shifts and a photo are
        // added next; the list was one more search away from it.
        router.push(
          created?.public_id ? `/employees/${created.public_id}` : "/employees",
        );
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
    });
  }

  const codeHint = nextCode.data?.employee_code
    ? t("employee.code_auto_hint", "Leave blank and ETHR assigns :code.", {
        code: nextCode.data.employee_code,
      })
    : t(
        "employee.code_auto_hint_generic",
        "Leave blank and ETHR assigns the next number.",
      );

  return (
    <>
      <PageActions
        actions={
          <Button variant="outline" size="sm" asChild>
            <Link href="/employees/import">
              {t("employee.import_instead", "Import a file instead")}
            </Link>
          </Button>
        }
      />

      <form onSubmit={handleSubmit} noValidate className="space-y-6">
        <p className="text-sm text-muted-foreground">
          {t("employee.required_note", "Fields marked * are required.")}
        </p>

        {errorCount > 0 && (
          <div
            role="alert"
            className="flex items-start gap-3 rounded-lg border border-destructive-edge bg-destructive-soft p-3 text-sm text-destructive-on-soft"
          >
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            <p>
              {t(
                "employee.errors_summary",
                "Some fields need a change. Each one says what below it.",
              )}
            </p>
          </div>
        )}

        {/* Every control's `id` is the API field name so a 422's `errors` key
            maps straight onto it — that is also what lets the submit handler
            focus the first invalid field. */}
        <div key={formKey} className="space-y-6">
          <Section
            icon={<UserRound className="h-4 w-4" />}
            title={t("employee.section_person", "Person")}
            description={t(
              "employee.section_person_desc",
              "Who they are. Only the name is required here.",
            )}
          >
            <FormField
              id="name"
              label={t("employee.full_name", "Full Name")}
              required
              error={errors.name}
            >
              <Input
                value={form.name}
                onChange={(e) => updateField("name", e.target.value)}
                autoComplete="off"
              />
            </FormField>

            <FormField
              id="name_am"
              label={t("employee.name_am", "Name in Amharic")}
              error={errors.name_am}
            >
              <Input
                value={form.name_am}
                onChange={(e) => updateField("name_am", e.target.value)}
                lang="am"
                autoComplete="off"
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
              id="nationality_status"
              label={t("employee.nationality_status")}
              hint={t("employee.nationality_status_hint")}
              error={errors.nationality_status}
              className="sm:col-span-2"
            >
              <NationalityStatusSelect
                id="nationality_status"
                value={form.nationality_status}
                onChange={(v) => updateField("nationality_status", v)}
              />
            </FormField>
          </Section>

          <Section
            icon={<Mail className="h-4 w-4" />}
            title={t("employee.section_contact", "Contact and sign-in")}
            description={t(
              "employee.section_contact_desc",
              "How to reach them, and whether they get an ETHR login.",
            )}
          >
            <FormField
              id="email"
              label={t("common.email", "Email")}
              error={errors.email}
            >
              <Input
                type="email"
                value={form.email}
                onChange={(e) => updateField("email", e.target.value)}
                autoComplete="off"
              />
            </FormField>

            <FormField
              id="phone"
              label={t("common.phone", "Phone")}
              error={errors.phone}
            >
              <Input
                type="tel"
                value={form.phone}
                onChange={(e) => updateField("phone", e.target.value)}
                placeholder="+251 9..."
                autoComplete="off"
              />
            </FormField>

            <div className="flex items-start gap-3 rounded-md border border-border-default p-4 sm:col-span-2">
              <Checkbox
                id="send_login"
                checked={sendLogin && !!form.email}
                onCheckedChange={(v) => setSendLogin(v === true)}
                disabled={!form.email}
                aria-describedby="send_login-hint"
              />
              <div className="space-y-1">
                <Label htmlFor="send_login" className="text-sm font-medium">
                  {t(
                    "employee.send_login",
                    "Send a login invitation to this email",
                  )}
                </Label>
                <p
                  id="send_login-hint"
                  className="text-xs text-muted-foreground"
                >
                  {form.email
                    ? t(
                        "employee.send_login_hint",
                        "They set a password from the email and can then request leave, see payslips and check in.",
                      )
                    : t(
                        "employee.login_needs_email",
                        "Add an email to send a login invitation.",
                      )}
                </p>
              </div>
            </div>
          </Section>

          <Section
            icon={<Briefcase className="h-4 w-4" />}
            title={t("employee.section_job", "Job")}
            description={t(
              "employee.section_job_desc",
              "When they start, where they work and who they report to.",
            )}
          >
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
              id="employee_code"
              label={t("employee.code", "Employee Code")}
              hint={codeHint}
              error={errors.employee_code}
            >
              <Input
                value={form.employee_code}
                onChange={(e) => updateField("employee_code", e.target.value)}
                placeholder={nextCode.data?.employee_code ?? "EMP-0001"}
                maxLength={30}
                className="font-mono"
                autoComplete="off"
              />
            </FormField>

            <FormField
              id="branch_id"
              label={t("common.branch", "Branch")}
              error={errors.branch_id}
            >
              <Select
                value={branchId}
                onValueChange={(v) => updateField("branch_id", v)}
              >
                <SelectTrigger id="branch_id">
                  <SelectValue
                    placeholder={t("employee.select_branch", "Select branch")}
                  />
                </SelectTrigger>
                <SelectContent>
                  {branchList.map((b: { public_id: string; name: string }) => (
                    <SelectItem key={b.public_id} value={b.public_id}>
                      {b.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </FormField>

            <FormField
              id="department_id"
              label={t("common.department", "Department")}
              error={errors.department_id}
            >
              <Select
                value={form.department_id}
                onValueChange={(v) => updateField("department_id", v)}
              >
                <SelectTrigger id="department_id">
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
              id="position_id"
              label={t("common.position", "Position")}
              error={errors.position_id}
            >
              <Select
                value={form.position_id}
                onValueChange={(v) => updateField("position_id", v)}
              >
                <SelectTrigger id="position_id">
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

            <FormField
              id="supervisor_id"
              label={t("employee.supervisor", "Supervisor")}
              error={errors.supervisor_id}
            >
              <RelationPicker
                id="supervisor_id"
                value={form.supervisor_id}
                options={(employeeOptions ?? []).map((e) => ({
                  value: e.public_id,
                  label: e.name,
                }))}
                onChange={(v) => updateField("supervisor_id", v)}
              />
            </FormField>

            <FormField
              id="team_id"
              label={t("employee.team", "Team")}
              error={errors.team_id}
            >
              <RelationPicker
                id="team_id"
                value={form.team_id}
                options={(teams?.data ?? []).map((x) => ({
                  value: x.public_id,
                  label: x.name,
                }))}
                onChange={(v) => updateField("team_id", v)}
              />
            </FormField>

            <FormField
              id="cost_center_id"
              label={t("employee.cost_center", "Cost centre")}
              error={errors.cost_center_id}
            >
              <RelationPicker
                id="cost_center_id"
                value={form.cost_center_id}
                options={(costCenters?.data ?? []).map((x) => ({
                  value: x.public_id,
                  label: x.name,
                }))}
                onChange={(v) => updateField("cost_center_id", v)}
              />
            </FormField>

            <FormField
              id="badge_number"
              label={t("employee.badge_number")}
              hint={t("employee.badge_number_hint")}
              error={errors.badge_number}
              className="sm:col-span-2"
            >
              <Input
                value={form.badge_number}
                maxLength={32}
                onChange={(e) => updateField("badge_number", e.target.value)}
                className="font-mono"
                autoComplete="off"
              />
            </FormField>
          </Section>

          <Section
            icon={<Wallet className="h-4 w-4" />}
            title={t("employee.section_pay", "Pay and identity")}
            description={t(
              "employee.section_pay_desc",
              "Used by payroll and the statutory schedules. You can add these later.",
            )}
          >
            <FormField
              id="salary_cents"
              label={t("employee.monthly_salary", "Monthly Salary (ETB)")}
              hint={t(
                "employee.salary_optional_hint",
                "Monthly gross pay. Leave it empty if you set pay later.",
              )}
              error={errors.salary_cents}
            >
              <CurrencyInput
                value={Number(form.salary_cents) || 0}
                onChange={(cents) =>
                  updateField("salary_cents", cents ? String(cents) : "")
                }
                placeholder="5000.00"
              />
            </FormField>

            <FormField
              id="tin"
              label={t("employee.tin", "TIN")}
              hint={t(
                "employee.tin_hint",
                "Taxpayer identification number. The tax schedule needs it.",
              )}
              error={errors.tin}
            >
              <Input
                value={form.tin}
                onChange={(e) => updateField("tin", e.target.value)}
                inputMode="numeric"
                maxLength={20}
                className="font-mono"
                autoComplete="off"
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
              className="sm:col-span-2"
            >
              <Input
                value={form.national_id}
                onChange={(e) => updateField("national_id", e.target.value)}
                placeholder="ETH-123-456"
                className="font-mono"
                autoComplete="off"
              />
            </FormField>
          </Section>
        </div>

        {/* In reach at any scroll position; the form is four cards long. On
            a phone the two saves share one row and Cancel is the breadcrumb,
            so the bar does not take a third of the screen. */}
        <div className="sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] z-10 -mx-4 flex gap-2 border-t border-border-default bg-background/95 px-4 py-3 backdrop-blur sm:justify-end sm:gap-3 md:-mx-6 md:px-6 lg:bottom-0">
          <Button
            variant="ghost"
            type="button"
            asChild
            className="hidden sm:inline-flex"
          >
            <Link href="/employees">{t("common.cancel", "Cancel")}</Link>
          </Button>
          <Button
            variant="outline"
            type="submit"
            className="flex-1 sm:flex-none"
            disabled={createEmployee.isPending}
            onClick={() => {
              afterSave.current = "another";
            }}
          >
            {t("employee.save_and_add_another", "Save and add another")}
          </Button>
          <Button
            type="submit"
            className="flex-1 sm:flex-none"
            disabled={createEmployee.isPending}
            onClick={() => {
              afterSave.current = "open";
            }}
          >
            {createEmployee.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("employee.save", "Save employee")}
          </Button>
        </div>
      </form>
    </>
  );
}

function Section({
  icon,
  title,
  description,
  children,
}: {
  icon: ReactNode;
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <Card>
      <CardHeader className="pb-4">
        <CardTitle className="flex items-center gap-2 text-base">
          <span className="text-muted-foreground" aria-hidden="true">
            {icon}
          </span>
          {title}
        </CardTitle>
        <p className="text-sm text-muted-foreground">{description}</p>
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-2">
        {children}
      </CardContent>
    </Card>
  );
}
