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
  | "has_kiosk_pin"
  | "gender"
  | "status"
  | "photo_url"
  | "photo_thumb_url"
  | "hire_date"
  | "salary_cents"
  | "department"
  | "branch"
  | "position"
  | "team"
  | "cost_center"
  | "supervisor"
  | "created_at"
>;
