import { describe, it, expect } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { EducationTab } from "@/features/employees/components/education-tab";

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const EDUCATION_URL = `*/api/v1/employees/${EMPLOYEE_ID}/education`;

/** The shape `EducationResource` renders — nothing more. */
function buildEducation(overrides: Record<string, unknown> = {}) {
  return {
    public_id: "01HZEDU000000000000000001",
    institution: "Addis Ababa University",
    degree: "BSc",
    field_of_study: "Computer Science",
    start_date: "2015-01-01",
    end_date: "2019-01-01",
    grade: "3.6",
    ...overrides,
  };
}

function renderTab() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<EducationTab employeeId={EMPLOYEE_ID} />, {
    wrapper: Wrapper,
  });
}

describe("<EducationTab>", () => {
  it("lists the records the endpoint returns as a bare array", async () => {
    // Regression: the tab read `data.data` from an unwrapped collection, so it
    // said "No education records" no matter how many there were.
    server.use(
      http.get(EDUCATION_URL, () => HttpResponse.json([buildEducation()])),
    );
    renderTab();

    expect(
      await screen.findByText("Addis Ababa University"),
    ).toBeInTheDocument();
    expect(screen.queryByText("No education records")).not.toBeInTheDocument();
  });

  it("shows the years and grade from the dates the resource returns", async () => {
    // Regression: rows read `start_year`, `end_year` and `gpa`, none of which
    // EducationResource returns, so no record ever showed when or how well.
    server.use(
      http.get(EDUCATION_URL, () => HttpResponse.json([buildEducation()])),
    );
    renderTab();

    expect(await screen.findByText("2015 – 2019")).toBeInTheDocument();
    expect(screen.getByText("GPA: 3.6")).toBeInTheDocument();
  });

  it("saves the years and GPA under the names StoreEducationRequest reads", async () => {
    // Regression: the form posted `start_year`, `end_year` and `gpa`; the
    // FormRequest validates `start_date`, `end_date` and `grade`, so
    // `validated()` dropped all three and every record was stored undated and
    // ungraded behind an "Education added" toast.
    server.use(http.get(EDUCATION_URL, () => HttpResponse.json([])));
    const bodies: Array<Record<string, unknown>> = [];
    server.use(
      http.post(EDUCATION_URL, async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json(buildEducation(), { status: 201 });
      }),
    );

    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByRole("button", { name: /Add/ }));
    const dialog = await screen.findByRole("dialog");
    await user.type(
      within(dialog).getByLabelText(/Institution/),
      "Addis Ababa University",
    );
    await user.type(within(dialog).getByLabelText(/Degree/), "BSc");
    await user.type(within(dialog).getByLabelText(/Start Year/), "2015");
    await user.type(within(dialog).getByLabelText(/End Year/), "2019");
    await user.type(within(dialog).getByLabelText(/GPA/), "3.6");
    await user.click(within(dialog).getByRole("button", { name: /^Add$/ }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({
      institution: "Addis Ababa University",
      degree: "BSc",
      start_date: "2015-01-01",
      end_date: "2019-01-01",
      grade: "3.6",
    });
    expect(bodies[0]).not.toHaveProperty("start_year");
    expect(bodies[0]).not.toHaveProperty("end_year");
    expect(bodies[0]).not.toHaveProperty("gpa");
  });
});
