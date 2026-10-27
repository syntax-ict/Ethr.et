import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { ApplicantsPanel } from "@/features/recruitment/components/applicants-panel";
import type { JobApplication } from "@/features/recruitment/api";

/**
 * The applicant panel on a vacancy: the list, and the decisions on one
 * application. The API enforces every rule; these tests pin that the panel
 * offers only the actions the person and the stage allow.
 */
const can = vi.fn(() => ({
  manageRecruitment: true,
  approveOffers: true,
  manageEmployees: true,
  updateEmployeeFinancial: true,
}));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ can: can() }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function application(overrides: Partial<JobApplication>): JobApplication {
  return {
    public_id: "a1",
    name: "Hanna Tesfaye",
    email: "hanna@example.et",
    phone: "+251911223344",
    source: "email",
    cover_note: null,
    stage: "applied",
    offer: null,
    hired_at: null,
    closed_reason: null,
    closed_at: null,
    erased: false,
    events: [],
    possible_duplicates: [],
    created_at: "2026-10-20T08:00:00Z",
    ...overrides,
  };
}

const approvedOffer = {
  start_date: "2026-11-03",
  salary_cents: 1_500_000,
  notes: null,
  made_by: "Selam HR",
  made_by_me: false,
  approved_by: "Dawit Admin",
  approved_at: "2026-10-25T08:00:00Z",
} as unknown as NonNullable<JobApplication["offer"]>;

function renderPanel() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <ApplicantsPanel
        vacancy={{ public_id: "v1", title: "Payroll officer" }}
        onClose={() => {}}
      />
    </QueryClientProvider>,
  );
}

function listOf(rows: JobApplication[]) {
  server.use(
    http.get("*/vacancies/v1/applications", () =>
      HttpResponse.json({
        data: rows,
        meta: { current_page: 1, last_page: 1, total: rows.length },
      }),
    ),
  );
}

beforeEach(() => {
  can.mockReturnValue({
    manageRecruitment: true,
    approveOffers: true,
    manageEmployees: true,
    updateEmployeeFinancial: true,
  });
  localStorage.setItem("locale", "en");
});

describe("Applicants panel", () => {
  it("says so when nobody has applied yet", async () => {
    listOf([]);
    renderPanel();

    expect(await screen.findByText("No applicants yet")).toBeInTheDocument();
  });

  it("offers only the moves the stage allows", async () => {
    listOf([application({ stage: "screening" })]);
    server.use(
      http.get("*/job-applications/a1", () =>
        HttpResponse.json(application({ stage: "screening" })),
      ),
    );
    renderPanel();

    fireEvent.click(await screen.findByText("Hanna Tesfaye"));

    expect(
      await screen.findByRole("button", { name: "Invite to interview" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Make an offer" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Hire" }),
    ).not.toBeInTheDocument();
  });

  it("does not let whoever made the offer approve it", async () => {
    const pending = {
      ...approvedOffer,
      approved_by: null,
      approved_at: null,
      made_by_me: true,
    } as unknown as NonNullable<JobApplication["offer"]>;
    listOf([application({ stage: "offer", offer: pending })]);
    server.use(
      http.get("*/job-applications/a1", () =>
        HttpResponse.json(application({ stage: "offer", offer: pending })),
      ),
    );
    renderPanel();

    fireEvent.click(await screen.findByText("Hanna Tesfaye"));

    expect(
      await screen.findByText(/waiting for approval by someone other than/i),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Approve offer" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Hire" }),
    ).not.toBeInTheDocument();
  });

  it("hires from an approved offer, starting on the offered date", async () => {
    let body: Record<string, unknown> | null = null;
    listOf([application({ stage: "offer", offer: approvedOffer })]);
    server.use(
      http.get("*/job-applications/a1", () =>
        HttpResponse.json(
          application({ stage: "offer", offer: approvedOffer }),
        ),
      ),
      http.post("*/job-applications/a1/hire", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          { public_id: "e1", name: "Hanna Tesfaye" },
          { status: 201 },
        );
      }),
    );
    renderPanel();

    fireEvent.click(await screen.findByText("Hanna Tesfaye"));
    fireEvent.click(await screen.findByRole("button", { name: "Hire" }));
    const submit = screen
      .getAllByRole("button", { name: "Hire" })
      .find((b) => b.getAttribute("type") === "submit");
    fireEvent.click(submit!);

    await waitFor(() =>
      expect(body).toEqual({
        hire_date: "2026-11-03",
        status: "hired",
        probation_end_date: null,
      }),
    );
  });

  it("shows a possible duplicate without merging anything", async () => {
    const row = application({
      possible_duplicates: [
        { kind: "employee", public_id: "e9", name: "Hanna T.", vacancy: null },
      ],
    });
    listOf([row]);
    server.use(http.get("*/job-applications/a1", () => HttpResponse.json(row)));
    renderPanel();

    fireEvent.click(await screen.findByText("Hanna Tesfaye"));

    expect(await screen.findByText("Hanna T., employee")).toBeInTheDocument();
    expect(screen.getByText(/nothing is merged/i)).toBeInTheDocument();
  });
});
