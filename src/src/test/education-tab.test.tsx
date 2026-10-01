import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
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
});
