import { describe, it, expect, vi } from "vitest";
import {
  render,
  screen,
  fireEvent,
  waitFor,
  within,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { IndustryConfigStep } from "@/features/onboarding/v2/components/industry-config-step";
import { AccessStep } from "@/features/onboarding/v2/components/access-step";
import { ReadinessStep } from "@/features/onboarding/v2/components/readiness-step";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
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
    fireEvent.click(screen.getByText("Review proposal"));

    // Scored review renders the sections with a confidence badge.
    expect(await screen.findByText("Administration")).toBeInTheDocument();
    // A summary of what was chosen, not a confidence score.
    expect(screen.getByText("Type of organisation")).toBeInTheDocument();
    expect(screen.queryByText(/confidence/i)).not.toBeInTheDocument();

    // Remove the "Administration" department specifically, then apply.
    const adminChip = screen.getByText("Administration");
    const removeBtn = adminChip.querySelector("button");
    fireEvent.click(removeBtn as HTMLButtonElement);
    fireEvent.click(screen.getByText("Apply configuration"));

    await waitFor(() => expect(applied).toHaveBeenCalled());

    const deps = (captured.plan?.departments ?? []) as string[];
    expect(deps).toHaveLength(2);
    expect(deps).not.toContain("Administration");
  });
});

describe("IndustryConfigStep — follows the sign-up type", () => {
  const catalog = [
    {
      key: "ministry",
      label: "Ministry",
      label_am: "ሚኒስቴር",
      base: "government",
      icon: "x",
      group: "public_sector",
    },
    {
      key: "woreda_administration",
      label: "Woreda administration",
      label_am: "ወረዳ አስተዳደር",
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
    {
      key: "insurance",
      label: "Insurance",
      label_am: "ኢንሹራንስ",
      base: "bank",
      icon: "x",
      group: "financial",
    },
    {
      key: "hotel",
      label: "Hotel",
      label_am: "ሆቴል",
      base: "hotel",
      icon: "x",
      group: "hospitality",
    },
  ];

  function signedUpAs(type: string) {
    server.use(
      http.get("*/api/v1/onboarding/industries", () =>
        HttpResponse.json({ data: catalog }),
      ),
      http.get("*/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "01HZUSER", name: "Abebe", role: "tenant_admin" },
          permissions: [],
          tenant: {
            public_id: "01HZTENANT",
            name: "Acme",
            subdomain: "acme",
            status: "trial",
            type,
          },
        }),
      ),
    );
  }

  it("offers only government types to an organisation that signed up as government", async () => {
    signedUpAs("government");
    renderWithClient(<IndustryConfigStep />);

    expect(
      await screen.findByText("Woreda administration"),
    ).toBeInTheDocument();
    expect(screen.getByText("Ministry")).toBeInTheDocument();
    expect(screen.queryByText("Bank")).not.toBeInTheDocument();
    expect(screen.queryByText("Hotel")).not.toBeInTheDocument();
    // Several government types, so none is chosen for them.
    expect(screen.getByRole("radio", { name: "Ministry" })).toHaveAttribute(
      "aria-checked",
      "false",
    );

    fireEvent.click(screen.getByText("Show every type of organisation"));
    expect(screen.getByText("Bank")).toBeInTheDocument();
    expect(screen.getByText("Hotel")).toBeInTheDocument();
  });

  it("starts a bank on Bank and offers the other financial types beside it", async () => {
    signedUpAs("bank");
    renderWithClient(<IndustryConfigStep />);

    expect(await screen.findByRole("radio", { name: "Bank" })).toHaveAttribute(
      "aria-checked",
      "true",
    );
    expect(screen.getByText("Insurance")).toBeInTheDocument();
    expect(screen.queryByText("Ministry")).not.toBeInTheDocument();
  });

  it("offers every type to an organisation that signed up as general", async () => {
    signedUpAs("general");
    renderWithClient(<IndustryConfigStep />);

    expect(await screen.findByText("Ministry")).toBeInTheDocument();
    expect(screen.getByText("Bank")).toBeInTheDocument();
    expect(
      screen.queryByText("Show every type of organisation"),
    ).not.toBeInTheDocument();
  });
});

describe("IndustryConfigStep — composed profiles and review", () => {
  it("shows the industry's usual profiles, sends only real choices, and holds apply until the review is read", async () => {
    const previewBodies: Record<string, unknown>[] = [];
    const applied: { body?: Record<string, unknown> } = {};

    server.use(
      http.get("*/api/v1/onboarding/industries", () =>
        HttpResponse.json({
          data: [
            {
              key: "hospital",
              label: "Hospital",
              label_am: "ሆስፒታል",
              base: "hospital",
              icon: "x",
              group: "healthcare",
              profile: "continuous_24_7",
              policy: "ethiopia_private",
            },
          ],
          workforce_profiles: [
            {
              key: "standard_office",
              label: "Standard office hours",
              label_am: "መደበኛ የቢሮ ሰዓት",
              description: "Monday to Friday.",
            },
            {
              key: "continuous_24_7",
              label: "Round the clock (24/7)",
              label_am: "ሌት ተቀን (24/7)",
              description: "Four crews.",
            },
          ],
          policy_profiles: [
            {
              key: "ethiopia_private",
              label: "Ethiopia — private sector",
              label_am: "ኢትዮጵያ — የግል ዘርፍ",
              description: "Labour Proclamation 1156/2019.",
            },
          ],
        }),
      ),
      http.post(
        "*/api/v1/onboarding/configuration/preview",
        async ({ request }) => {
          previewBodies.push((await request.json()) as Record<string, unknown>);
          return HttpResponse.json({
            industry: {
              key: "hospital",
              label: "Hospital",
              label_am: "ሆስፒታል",
              base: "hospital",
            },
            signals: {},
            overall_confidence: 0.8,
            sections: {
              shifts: {
                source: "industry_default",
                confidence: 0.8,
                items: [
                  { name: "Night", start_time: "22:00", end_time: "06:00" },
                ],
              },
              rotations: {
                source: "industry_default",
                confidence: 0.8,
                items: [{ name: "Continuous 2-2-2-2", cycle_days: 8 }],
              },
              allowances: {
                source: "industry_default",
                confidence: 0.8,
                items: [{ name: "Hazard allowance" }],
              },
            },
            plan: {},
            profiles: {
              workforce: {
                key: "continuous_24_7",
                label: "Round the clock (24/7)",
                label_am: "ሌት ተቀን (24/7)",
              },
              policy: {
                key: "ethiopia_private",
                label: "Ethiopia — private sector",
                label_am: "ኢትዮጵያ — የግል ዘርፍ",
              },
            },
            review: [
              { code: "leave_private", message: "Confirm the leave values." },
            ],
          });
        },
      ),
      http.post(
        "*/api/v1/onboarding/configuration/apply",
        async ({ request }) => {
          applied.body = (await request.json()) as Record<string, unknown>;
          return HttpResponse.json({
            message: "ok",
            industry: null,
            provisioned: { resources: {}, total_created: 4, warnings: [] },
          });
        },
      ),
    );

    renderWithClient(<IndustryConfigStep />);

    fireEvent.click(await screen.findByText("Hospital"));

    // The industry's usual profiles are what the pickers show.
    expect(
      await screen.findByText("Round the clock (24/7)"),
    ).toBeInTheDocument();
    expect(screen.getByText("Ethiopia — private sector")).toBeInTheDocument();

    fireEvent.click(screen.getByText("Review proposal"));

    // A default is not sent as if it were a choice.
    await screen.findByText("Night 22:00–06:00");
    expect(previewBodies[0]).not.toHaveProperty("workforce_profile");
    expect(previewBodies[0]).not.toHaveProperty("policy_profile");

    expect(
      screen.getByText("Continuous 2-2-2-2 · 8-day cycle"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Nobody's pay changes until you set one/),
    ).toBeInTheDocument();
    expect(screen.getByText("Confirm the leave values.")).toBeInTheDocument();

    const applyButton = screen.getByRole("button", {
      name: "Apply configuration",
    });
    expect(applyButton).toBeDisabled();

    fireEvent.click(
      screen.getByRole("checkbox", {
        name: "I have read these and will confirm the values",
      }),
    );
    expect(applyButton).toBeEnabled();
    fireEvent.click(applyButton);

    await waitFor(() => expect(applied.body).toBeDefined());
    expect(applied.body).toMatchObject({
      industry: "hospital",
      workforce_profile: "continuous_24_7",
      policy_profile: "ethiopia_private",
    });
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

  // Going live ends setup for everyone; it went through on one click.
  it("asks before going live, and sends nothing on Cancel", async () => {
    let posts = 0;
    server.use(
      http.get("*/api/v1/onboarding/readiness", () =>
        HttpResponse.json({
          overall_score: 100,
          level: "ready",
          categories: {},
          gaps: [],
        }),
      ),
      http.post("*/api/v1/onboarding/go-live", () => {
        posts++;
        return HttpResponse.json({ status: "live" });
      }),
    );
    const onLive = vi.fn();
    renderWithClient(<ReadinessStep onLive={onLive} />);

    fireEvent.click(await screen.findByRole("button", { name: /go live/i }));
    const dialog = await screen.findByRole("dialog", { name: "Go live now?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    );
    expect(posts).toBe(0);

    fireEvent.click(screen.getByRole("button", { name: /go live/i }));
    const again = await screen.findByRole("dialog", { name: "Go live now?" });
    fireEvent.click(within(again).getByRole("button", { name: "Go live" }));
    await waitFor(() => expect(onLive).toHaveBeenCalled());
    expect(posts).toBe(1);
  });
});
