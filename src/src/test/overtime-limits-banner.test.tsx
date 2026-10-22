import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { OvertimeLimitsBanner } from "@/features/payroll/components/overtime-limits-banner";

const LIMITS = {
  count: 2,
  daily_limit_minutes: 120,
  legal_daily_limit_minutes: 240,
  legal_weekly_limit_minutes: 720,
};

describe("<OvertimeLimitsBanner>", () => {
  it("names who went over which limit and says all of it is paid", () => {
    render(
      <OvertimeLimitsBanner
        limits={{
          ...LIMITS,
          employees: [
            {
              employee_public_id: "01HZEMP0000000000000000001",
              employee_name: "Abebe Kebede",
              overtime_minutes: 300,
              days_over_daily_limit: 1,
              days_over_legal_daily_limit: 1,
              weeks_over_legal_weekly_limit: 0,
              longest_week_minutes: 0,
            },
            {
              employee_public_id: null,
              employee_name: "Sara Tesfaye",
              overtime_minutes: 840,
              days_over_daily_limit: 4,
              days_over_legal_daily_limit: 0,
              weeks_over_legal_weekly_limit: 1,
              longest_week_minutes: 840,
            },
          ],
        }}
      />,
    );

    expect(
      screen.getByText(/2 employee\(s\) worked overtime past a limit/),
    ).toHaveTextContent(
      "your organisation's 2 hours a day, or the legal 4 hours a day or 12 hours a week",
    );
    expect(screen.getByText(/All of it is paid/)).toHaveTextContent(
      "not counted here",
    );
    expect(screen.getByRole("link", { name: "Abebe Kebede" })).toHaveAttribute(
      "href",
      "/employees/01HZEMP0000000000000000001",
    );
    expect(
      screen.getByText(
        /5 hours of overtime\. Over your limit on 1 day\(s\), over 4 hours on 1 day\(s\), over 12 hours in 0 week\(s\)\./,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        /14 hours of overtime\. Over your limit on 4 day\(s\), over 4 hours on 0 day\(s\), over 12 hours in 1 week\(s\)\./,
      ),
    ).toBeInTheDocument();
  });

  it("shows nothing when nobody went over", () => {
    const { container } = render(
      <OvertimeLimitsBanner limits={{ ...LIMITS, count: 0, employees: [] }} />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});
