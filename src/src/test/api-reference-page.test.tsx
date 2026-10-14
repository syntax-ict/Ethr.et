import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ApiReferencePage from "@/app/(dashboard)/settings/api-keys/reference/page";

/** A small slice of what `GET /api-keys/reference` returns. */
const REFERENCE = {
  openapi: "3.1.0",
  servers: [{ url: "https://acme.ethr.et/api/v1" }],
  paths: {
    "/employees": {
      get: {
        summary: "List employees",
        tags: ["Employees"],
        "x-abilities": ["read", "employees"],
        parameters: [
          { name: "per_page", in: "query", schema: { type: "integer" } },
        ],
        responses: {
          "200": {
            description: "Paginated set of `EmployeeResource`",
            content: {
              "application/json": {
                schema: {
                  type: "object",
                  properties: {
                    data: {
                      type: "array",
                      items: { $ref: "#/components/schemas/EmployeeResource" },
                    },
                  },
                  required: ["data"],
                },
              },
            },
          },
        },
      },
    },
    "/payroll/runs": {
      get: {
        summary: "List payroll runs",
        tags: ["Payroll"],
        "x-abilities": ["read", "payroll"],
        responses: {},
      },
    },
  },
  components: {
    schemas: {
      EmployeeResource: {
        type: "object",
        properties: {
          public_id: { type: "string" },
          name: { type: "string" },
        },
        required: ["public_id"],
      },
    },
  },
  "x-webhook-events": ["employee.created", "payroll.approved"],
};

function serve(role = "tenant_admin", permissions = ["apikey.manage"]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Test User", role },
        tenant: {
          public_id: "T1",
          name: "Acme",
          timezone: "Africa/Addis_Ababa",
        },
        permissions,
      }),
    ),
    http.get("*/api/v1/api-keys/reference", () => HttpResponse.json(REFERENCE)),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<ApiReferencePage />, { wrapper: Wrapper });
}

describe("<ApiReferencePage>", () => {
  it("explains how to call the API, from this organisation's own address", async () => {
    serve();
    renderPage();

    expect(
      await screen.findByRole("heading", { name: "Getting started" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/curl https:\/\/acme\.ethr\.et\/api\/v1\/employees/),
    ).toBeInTheDocument();
    expect(screen.getByText("employee.created")).toBeInTheDocument();
    expect(screen.getByText("payroll.approved")).toBeInTheDocument();
  });

  it("lists each operation with the abilities that allow it, and its answer's shape", async () => {
    serve();
    const user = userEvent.setup();
    renderPage();

    const row = await screen.findByText("/employees");
    expect(screen.getByText("/payroll/runs")).toBeInTheDocument();

    await user.click(row);

    expect(
      screen.getByText("Needs one of these abilities: read, employees"),
    ).toBeInTheDocument();
    // The paginated answer, with the referenced resource drawn inline.
    expect(screen.getByText(/EmployeeResource\[\]/)).toBeInTheDocument();
    expect(screen.getByText("public_id")).toBeInTheDocument();
  });

  it("narrows the list as you search", async () => {
    serve();
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("/employees");
    await user.type(screen.getByRole("searchbox"), "payroll");

    expect(screen.queryByText("/employees")).not.toBeInTheDocument();
    expect(screen.getByText("/payroll/runs")).toBeInTheDocument();
  });

  it("is not shown to someone who does not manage API keys", async () => {
    serve("employee", []);
    renderPage();

    expect(await screen.findByTestId("role-gate-denied")).toBeInTheDocument();
  });
});
