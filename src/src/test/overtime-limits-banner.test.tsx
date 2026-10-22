import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { OvertimeLimitsBanner } from "@/features/payroll/components/overtime-limits-banner";

describe("<OvertimeLimitsBanner>", () => {
  it("names who went over and says all of it is paid", () => {
    render(
      <OvertimeLimitsBanner
        limits={{
          count: 2,
          daily_limit_minutes: 120,
          employees: [
            {
              employee_public_id: "01HZEMP0000000000000000001",
              employee_name: "Abebe Kebede",
              overtime_minutes: 540,
              days_over_daily_limit: 3,
              over_monthly_limit: false,
            },
            {
              employee_public_id: null,
              employee_name: "Sara Tesfaye",
              overtime_minutes: 1320,
              days_over_daily_limit: 0,
              over_monthly_limit: true,
            },
          ],
        }}
      />,
    );

    expect(
      screen.getByText(/2 employee\(s\) worked overtime past the limits/),
    ).toHaveTextContent("more than 2 hours on a day");
    expect(screen.getByText(/All of it is paid/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Abebe Kebede" })).toHaveAttribute(
      "href",
      "/employees/01HZEMP0000000000000000001",
    );
    expect(
      screen.getByText(
        /9 hours in the period; 3 day\(s\) over the daily limit/,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/22 hours in the period, over the monthly limit/),
    ).toBeInTheDocument();
  });

  it("shows nothing when nobody went over", () => {
    const { container } = render(
      <OvertimeLimitsBanner
        limits={{ count: 0, daily_limit_minutes: 120, employees: [] }}
      />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});
