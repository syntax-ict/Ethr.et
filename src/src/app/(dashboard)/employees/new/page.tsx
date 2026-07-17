"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { ArrowLeft, Loader2 } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
import { RoleGate } from "@/components/shared/role-gate";
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
    marital_status: "single",
    hire_date: "",
    salary_cents: "",
    department_public_id: "",
    branch_public_id: "",
    position_public_id: "",
  });

  function updateField(field: string, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    createEmployee.mutate(
      {
        ...form,
        salary_cents: parseInt(form.salary_cents) * 100 || 0,
      },
      {
        onSuccess: () => {
          toast.success(t("employee.created", "Employee created successfully"));
          router.push("/employees");
        },
        onError: (err: unknown) => {
          const axiosError = err as {
            response?: { data?: { detail?: string } };
          };
          toast.error(
            axiosError.response?.data?.detail ||
              t("employee.create_failed", "Failed to create employee"),
          );
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
              <div>
                <Label htmlFor="name">
                  {t("employee.full_name", "Full Name")} *
                </Label>
                <Input
                  id="name"
                  value={form.name}
                  onChange={(e) => updateField("name", e.target.value)}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="email">{t("common.email", "Email")} *</Label>
                <Input
                  id="email"
                  type="email"
                  value={form.email}
                  onChange={(e) => updateField("email", e.target.value)}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="phone">{t("common.phone", "Phone")}</Label>
                <Input
                  id="phone"
                  value={form.phone}
                  onChange={(e) => updateField("phone", e.target.value)}
                  placeholder="+251..."
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="gender">{t("employee.gender", "Gender")}</Label>
                <Select
                  value={form.gender}
                  onValueChange={(v) => updateField("gender", v)}
                >
                  <SelectTrigger className="mt-1">
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
              </div>
              <div>
                <Label htmlFor="dob">
                  {t("employee.date_of_birth", "Date of Birth")}
                </Label>
                <Input
                  id="dob"
                  type="date"
                  value={form.date_of_birth}
                  onChange={(e) => updateField("date_of_birth", e.target.value)}
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="marital">
                  {t("employee.marital_status", "Marital Status")}
                </Label>
                <Select
                  value={form.marital_status}
                  onValueChange={(v) => updateField("marital_status", v)}
                >
                  <SelectTrigger className="mt-1">
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
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("employee.employment_details", "Employment Details")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <Label htmlFor="code">
                  {t("employee.code", "Employee Code")} *
                </Label>
                <Input
                  id="code"
                  value={form.employee_code}
                  onChange={(e) => updateField("employee_code", e.target.value)}
                  required
                  placeholder="EMP-0001"
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="hire">
                  {t("employee.hire_date", "Hire Date")} *
                </Label>
                <Input
                  id="hire"
                  type="date"
                  value={form.hire_date}
                  onChange={(e) => updateField("hire_date", e.target.value)}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="salary">
                  {t("employee.monthly_salary", "Monthly Salary (ETB)")} *
                </Label>
                <Input
                  id="salary"
                  type="number"
                  value={form.salary_cents}
                  onChange={(e) => updateField("salary_cents", e.target.value)}
                  required
                  placeholder="5000"
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="nationality">
                  {t("employee.nationality", "Nationality")}
                </Label>
                <Input
                  id="nationality"
                  value={form.nationality}
                  onChange={(e) => updateField("nationality", e.target.value)}
                  className="mt-1"
                />
              </div>
              <div>
                <Label>{t("common.department", "Department")}</Label>
                <Select
                  value={form.department_public_id}
                  onValueChange={(v) => updateField("department_public_id", v)}
                >
                  <SelectTrigger className="mt-1">
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
              </div>
              <div>
                <Label>{t("common.branch", "Branch")}</Label>
                <Select
                  value={form.branch_public_id}
                  onValueChange={(v) => updateField("branch_public_id", v)}
                >
                  <SelectTrigger className="mt-1">
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
              </div>
              <div>
                <Label>{t("common.position", "Position")}</Label>
                <Select
                  value={form.position_public_id}
                  onValueChange={(v) => updateField("position_public_id", v)}
                >
                  <SelectTrigger className="mt-1">
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
              </div>
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
