import { describe, it, expect } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { GradeSalaryStepsDialog } from "@/features/organization/components/grade-salary-steps-dialog";
import type { Grade, GradeSalaryStep } from "@/features/organization/api";

const GRADE: Grade = {
  public_id: "01HZGRADE0000000000000001",
  name: "Grade V",
  min_salary_cents: 1_000_000,
  max_salary_cents: 2_000_000,
  sort_order: 5,
};

const STEPS_URL = `*/api/v1/organization/grades/${GRADE.public_id}/salary-steps`;

function buildStep(overrides: Partial<GradeSalaryStep> = {}): GradeSalaryStep {
  return {
    public_id: "01HZSTEP00000000000000001",
    step: 1,
    salary_cents: 1_000_000,
    created_at: "2026-01-01T00:00:00Z",
    ...overrides,
  };
}

function renderDialog() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(
    <GradeSalaryStepsDialog grade={GRADE} open onOpenChange={() => {}} />,
    { wrapper: Wrapper },
  );
}

describe("<GradeSalaryStepsDialog>", () => {
  it("shows an empty state when the grade has no steps", async () => {
    server.use(http.get(STEPS_URL, () => HttpResponse.json([])));
    renderDialog();

    expect(await screen.findByText("No salary steps yet")).toBeInTheDocument();
  });

  it("lists existing steps with their salary", async () => {
    server.use(
      http.get(STEPS_URL, () =>
        HttpResponse.json([
          buildStep({ step: 1, salary_cents: 1_000_000 }),
          buildStep({
            public_id: "01HZSTEP00000000000000002",
            step: 2,
            salary_cents: 1_300_000,
          }),
        ]),
      ),
    );
    renderDialog();

    expect(await screen.findByText("13,000.00 ETB")).toBeInTheDocument();
    expect(screen.getByText("10,000.00 ETB")).toBeInTheDocument();
  });

  it("adds a step with the typed step number and salary", async () => {
    let requestBody: unknown;
    server.use(
      http.get(STEPS_URL, () => HttpResponse.json([])),
      http.post(STEPS_URL, async ({ request }) => {
        requestBody = await request.json();
        return HttpResponse.json(buildStep(), { status: 201 });
      }),
    );
    renderDialog();
    await screen.findByText("No salary steps yet");

    const dialog = screen.getByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText("Step"), "1");
    await userEvent.type(within(dialog).getByLabelText(/Salary/), "12000");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /add step/i }),
    );

    expect(requestBody).toEqual({ step: 1, salary_cents: 1_200_000 });
  });

  it("does not add a step to the list when the server rejects it", async () => {
    let postCount = 0;
    server.use(
      http.get(STEPS_URL, () => HttpResponse.json([])),
      http.post(STEPS_URL, () => {
        postCount += 1;
        return HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: {
              salary_cents: ["Out of range."],
            },
          },
          { status: 422 },
        );
      }),
    );
    renderDialog();
    await screen.findByText("No salary steps yet");

    const dialog = screen.getByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText("Step"), "1");
    // In-band (the grade is 10,000–20,000 ETB) so the client lets it through
    // and the *server's* rejection is what this test exercises. An out-of-band
    // figure never reaches the network now — see the sibling test below.
    await userEvent.type(within(dialog).getByLabelText(/Salary/), "15000");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /add step/i }),
    );

    // The POST was attempted, and the empty state persists (no optimistic add
    // survived the rejection).
    await waitFor(() => expect(postCount).toBe(1));
    expect(screen.getByText("No salary steps yet")).toBeInTheDocument();

    // The server's message lands on the field it names rather than in a toast.
    expect(await screen.findByText("Out of range.")).toBeInTheDocument();
  });

  it("keeps an out-of-band salary off the network entirely", async () => {
    // The grade band is a server rule the client can evaluate itself, and the
    // dialog already states it in its hint — so a figure outside it is a
    // round trip with a known answer.
    let postCount = 0;
    server.use(
      http.get(STEPS_URL, () => HttpResponse.json([])),
      http.post(STEPS_URL, () => {
        postCount += 1;
        return HttpResponse.json({}, { status: 201 });
      }),
    );
    renderDialog();
    await screen.findByText("No salary steps yet");

    const dialog = screen.getByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText("Step"), "1");
    await userEvent.type(within(dialog).getByLabelText(/Salary/), "50000");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /add step/i }),
    );

    await waitFor(() =>
      expect(within(dialog).getByLabelText(/Salary/)).toHaveAttribute(
        "aria-invalid",
        "true",
      ),
    );
    expect(postCount).toBe(0);
  });
});
