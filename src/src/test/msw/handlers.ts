import { http, HttpResponse } from "msw";
import type { components } from "@/api/generated";

type EmployeeResource = components["schemas"]["EmployeeResource"];

export function buildEmployee(
  overrides: Partial<EmployeeResource> = {},
): EmployeeResource {
  return {
    public_id: "01HZEMPLOYEE0000000000001",
    name: "Abebe Kebede",
    name_am: "አበበ ከበደ",
    email: "abebe@example.com",
    phone: "+251911223344",
    employee_code: "EMP-0001",
    gender: "male",
    date_of_birth: "1990-01-01",
    nationality: "Ethiopian",
    marital_status: "single",
    status: "active",
    hire_date: "2024-01-01",
    probation_end_date: "2024-04-01",
    confirmation_date: "2024-04-01",
    termination_date: "",
    // Kept internally consistent: a photo_path implies presigned URLs, which
    // EmployeeResource always returns alongside it.
    photo_path: "employees/01HZEMPLOYEE0000000000001.jpg",
    photo_url: "https://storage.test/employees/01HZEMPLOYEE0000000000001.jpg",
    photo_thumb_url:
      "https://storage.test/employees/01HZEMPLOYEE0000000000001-150.jpg",
    created_at: "2024-01-01T00:00:00Z",
    updated_at: "2024-01-01T00:00:00Z",
    ...overrides,
  };
}

// Wildcard-origin patterns so the same handlers match regardless of the
// jsdom test origin axios resolves the relative apiClient baseURL against.
export const handlers = [
  http.get("*/api/v1/employees", () => {
    const employees = [buildEmployee()];
    return HttpResponse.json({
      data: employees,
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: employees.length,
        from: 1,
        to: employees.length,
      },
      links: { first: "", last: "", prev: null, next: null },
    });
  }),

  http.get("*/api/v1/employees/:publicId", ({ params }) => {
    return HttpResponse.json(
      buildEmployee({ public_id: params.publicId as string }),
    );
  }),
];
