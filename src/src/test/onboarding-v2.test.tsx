import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { IndustryConfigStep } from "@/features/onboarding/v2/components/industry-config-step";
import { AccessStep } from "@/features/onboarding/v2/components/access-step";
import { ReadinessStep } from "@/features/onboarding/v2/components/readiness-step";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

describe("IndustryConfigStep", () => {
  function industries() {
    return {
      data: [
        {
          key: "ministry",
          label: "Ministry",
          label_am: "ሚኒስቴር",
          base: "government",
          icon: "x",
          group: "public_sector",
        },
        {
          key: "bank",
          label: "Bank",
          label_am: "ባንክ",
          base: "bank",
          icon: "x",
          group: "financial",
        },
      ],
    };
  }

  function plan() {
    return {
      industry: {
        key: "ministry",
        label: "Ministry",
        label_am: "ሚኒስቴር",
        base: "government",
      },
      signals: {},
      overall_confidence: 0.82,
      sections: {
        departments: {
          source: "industry_default",
          confidence: 0.8,
          items: ["Administration", "Finance", "HR"],
        },
        branches: {
          source: "heuristic",
          confidence: 0.6,
          items: [{ name: "Headquarters", city: "Addis Ababa" }],
        },
      },
      plan: {
        departments: ["Administration", "Finance", "HR"],
        branches: [{ name: "Headquarters", city: "Addis Ababa" }],
      },
    };
  }

  it("previews a scored plan, lets you remove an item, and applies", async () => {
    const applied = vi.fn();
    // Captured on an object rather than a `let`: TS control-flow analysis can't
    // see the assignment inside the MSW handler closure and would narrow a bare
    // variable to `null` at the assertion below.
    const captured: { plan?: Record<string, unknown> } = {};

    server.use(
      http.get("*/api/v1/onboarding/industries", () =>
        HttpResponse.json(industries()),
      ),
      http.post("*/api/v1/onboarding/configuration/preview", () =>
        HttpResponse.json(plan()),
      ),
      http.post(
        "*/api/v1/onboarding/configuration/apply",
        async ({ request }) => {
          const body = (await request.json()) as {
            plan: Record<string, unknown>;
          };
          captured.plan = body.plan;
          return HttpResponse.json({
            message: "ok",
            industry: {
              key: "ministry",
              label: "Ministry",
              base: "government",
            },
            provisioned: { resources: {}, total_created: 5, warnings: [] },
          });
        },
      ),
    );

    renderWithClient(<IndustryConfigStep onApplied={applied} />);

    // Industry picker renders after the query resolves.
    const ministry = await screen.findByText("Ministry");
    fireEvent.click(ministry);
    fireEvent.click(screen.getByText("Generate configuration"));

    // Scored review renders the sections with a confidence badge.
    expect(await screen.findByText("Administration")).toBeInTheDocument();
    expect(screen.getByText(/Overall confidence/i)).toBeInTheDocument();

    // Remove the "Administration" department specifically, then apply.
    const adminChip = screen.getByText("Administration");
    const removeBtn = adminChip.querySelector("button");
    fireEvent.click(removeBtn as HTMLButtonElement);
    fireEvent.click(screen.getByText("Accept & apply"));

    await waitFor(() => expect(applied).toHaveBeenCalled());

    const deps = (captured.plan?.departments ?? []) as string[];
    expect(deps).toHaveLength(2);
    expect(deps).not.toContain("Administration");
  });
});

describe("AccessStep", () => {
  it("loads the policy, toggles an identifier, and saves", async () => {
    let saved: unknown = null;
    server.use(
      http.get("*/api/v1/onboarding/access", () =>
        HttpResponse.json({
          available: ["email", "phone", "employee_code"],
          login_identifiers: ["email"],
          role_defaults: {},
        }),
      ),
      http.put("*/api/v1/onboarding/access", async ({ request }) => {
        saved = await request.json();
        return HttpResponse.json({
          available: ["email", "phone", "employee_code"],
          login_identifiers: ["email", "phone"],
          role_defaults: {},
        });
      }),
    );

    const onSaved = vi.fn();
    renderWithClient(<AccessStep onSaved={onSaved} />);

    const phone = await screen.findByText("Mobile number");
    fireEvent.click(phone);
    fireEvent.click(screen.getByText("Save login methods"));

    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    expect(
      (saved as { login_identifiers: string[] }).login_identifiers,
    ).toContain("phone");
  });
});

describe("ReadinessStep", () => {
  it("shows the score, category checks, and gap deep links", async () => {
    server.use(
      http.get("*/api/v1/onboarding/readiness", () =>
        HttpResponse.json({
          overall_score: 55,
          level: "not_ready",
          categories: {
            configuration: {
              score: 40,
              checks: [
                {
                  id: "branch_exists",
                  label: "At least one branch",
                  category: "configuration",
                  passed: false,
                  remediation: "/organization",
                },
              ],
            },
          },
          gaps: [
            {
              id: "has_employees",
              label: "Employees added",
              category: "data",
              passed: false,
              remediation: "/employees",
            },
          ],
        }),
      ),
    );

    renderWithClient(<ReadinessStep />);

    expect(await screen.findByText("55%")).toBeInTheDocument();
    expect(screen.getByText("Employees added")).toBeInTheDocument();
    const fixLink = screen.getByRole("link", { name: /Fix/i });
    expect(fixLink).toHaveAttribute("href", "/employees");
  });
});
