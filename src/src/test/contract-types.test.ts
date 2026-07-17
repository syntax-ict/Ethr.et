import { describe, it, expectTypeOf } from "vitest";
import type { components, paths } from "@/api/generated";
import type { Employee, Tenant, PaginatedResponse } from "@/api/types";

type ApiEmployee = components["schemas"]["EmployeeResource"];
type ApiTenant = components["schemas"]["TenantResource"];

describe("Contract type compatibility", () => {
  it("Employee has all required fields from API spec", () => {
    expectTypeOf<Employee>().toMatchTypeOf<
      Pick<
        ApiEmployee,
        | "public_id"
        | "name"
        | "name_am"
        | "email"
        | "phone"
        | "employee_code"
        | "gender"
        | "status"
        | "hire_date"
      >
    >();
  });

  it("Tenant has core fields from API spec", () => {
    expectTypeOf<Tenant["public_id"]>().toEqualTypeOf<ApiTenant["public_id"]>();
    expectTypeOf<Tenant["name"]>().toEqualTypeOf<ApiTenant["name"]>();
    expectTypeOf<Tenant["subdomain"]>().toEqualTypeOf<ApiTenant["subdomain"]>();
    expectTypeOf<Tenant["default_locale"]>().toEqualTypeOf<
      ApiTenant["default_locale"]
    >();
    expectTypeOf<Tenant["timezone"]>().toEqualTypeOf<ApiTenant["timezone"]>();
    expectTypeOf<Tenant["logo_path"]>().toEqualTypeOf<ApiTenant["logo_path"]>();
  });

  it("PaginatedResponse matches API pagination shape", () => {
    expectTypeOf<PaginatedResponse<unknown>["meta"]>().toMatchTypeOf<{
      current_page: number;
      last_page: number;
      per_page: number;
      total: number;
    }>();

    expectTypeOf<PaginatedResponse<unknown>["links"]>().toMatchTypeOf<{
      prev: string | null;
      next: string | null;
    }>();
  });

  it("generated spec has critical API paths", () => {
    expectTypeOf<paths>().toHaveProperty("/employees");
    expectTypeOf<paths>().toHaveProperty("/auth/login");
    expectTypeOf<paths>().toHaveProperty("/attendance");
    expectTypeOf<paths>().toHaveProperty("/payroll/runs");
    expectTypeOf<paths>().toHaveProperty("/roles");
    expectTypeOf<paths>().toHaveProperty("/permissions");
  });
});
