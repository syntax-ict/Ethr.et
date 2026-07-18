import type { components } from "@/api/generated";

// Derived from the generated OpenAPI schema rather than hand-duplicated, so
// a backend field rename (e.g. PositionResource.title) is caught by tsc
// instead of silently rendering "—" in the UI.
export type Employee = Pick<
  components["schemas"]["EmployeeResource"],
  | "public_id"
  | "name"
  | "name_am"
  | "email"
  | "phone"
  | "employee_code"
  | "gender"
  | "status"
  | "hire_date"
  | "salary_cents"
  | "department"
  | "branch"
  | "position"
  | "created_at"
>;

export interface EmployeeFormData {
  name: string;
  name_am?: string;
  email: string;
  phone: string;
  employee_code: string;
  gender: string;
  date_of_birth: string;
  nationality: string;
  marital_status: string;
  hire_date: string;
  salary_cents: number;
  department_public_id?: string;
  branch_public_id?: string;
  position_public_id?: string;
}
