import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import {
  MissingTinBanner,
  NoAttendanceBanner,
  PayChangesBanner,
  SharedBankAccountsBanner,
} from "@/features/payroll/components/pre-approval-checks";

const ABEBE = {
  employee_public_id: "01HZEMP0000000000000000001",
  employee_name: "Abebe Kebede",
};
const SARA = { employee_public_id: null, employee_name: "Sara Tesfaye" };

describe("checks before approval", () => {
  it("says whose net pay moved, by how much, and what moved most", () => {
    render(
      <PayChangesBanner
        changes={{
          count: 1,
          previous_period: "September 2026",
          first_payslip: 0,
          percent: 20,
          min_cents: 50_000,
          employees: [
            {
              ...ABEBE,
              previous_net_cents: 900_000,
              net_cents: 1_250_000,
              change_cents: 350_000,
              change_percent: 38.9,
              reasons: [
                { part: "basic_cents", change_cents: 500_000 },
                { part: "income_tax_cents", change_cents: 150_000 },
              ],
            },
          ],
        }}
      />,
    );

    expect(
      screen.getByText(/at least 20% and .*500.* away from September 2026/),
    ).toBeInTheDocument();
    const item = screen.getByRole("listitem");
    expect(item).toHaveTextContent(
      "9,000.00 ETB to 12,500.00 ETB (+3,500.00 ETB, +38.9%)",
    );
    expect(item).toHaveTextContent(
      "Moved most: Basic +5,000.00 ETB, Income tax +1,500.00 ETB",
    );
    expect(screen.getByRole("link", { name: "Abebe Kebede" })).toHaveAttribute(
      "href",
      "/employees/01HZEMP0000000000000000001",
    );
  });

  it("names the people who share an account and the banks, never a number", () => {
    render(
      <SharedBankAccountsBanner
        shared={{
          count: 1,
          pending_hashes: 0,
          groups: [{ banks: ["CBE"], employees: [ABEBE, SARA] }],
        }}
      />,
    );

    expect(
      screen.getByText(
        "1 bank account(s) receive the pay of more than one employee.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole("listitem")).toHaveTextContent(
      "Abebe Kebede, Sara Tesfaye (CBE)",
    );
  });

  it("says the accounts are not shown in a support session", () => {
    render(
      <SharedBankAccountsBanner
        shared={{
          count: 0,
          pending_hashes: 0,
          support_session: true,
          groups: [],
        }}
      />,
    );

    expect(
      screen.getByText(/not shown in a support session/),
    ).toBeInTheDocument();
  });

  it("says when some accounts cannot be compared yet", () => {
    render(
      <SharedBankAccountsBanner
        shared={{ count: 0, pending_hashes: 3, groups: [] }}
      />,
    );

    expect(
      screen.getByText(/3 employee\(s\) are still being prepared/),
    ).toBeInTheDocument();
  });

  it("lists people paid with no TIN and people paid with no attendance", () => {
    render(
      <>
        <MissingTinBanner missing={{ count: 1, employees: [SARA] }} />
        <NoAttendanceBanner
          none={{ count: 1, employees: [{ ...ABEBE, days: 21 }] }}
        />
      </>,
    );

    expect(screen.getByText(/have no TIN on record/)).toBeInTheDocument();
    expect(screen.getByText("Sara Tesfaye")).toBeInTheDocument();
    expect(
      screen.getByText(/no attendance at all in the period/),
    ).toBeInTheDocument();
    expect(screen.getAllByRole("listitem")[1]).toHaveTextContent(
      "Abebe Kebede: 21 expected workday(s)",
    );
  });

  it("shows nothing when every list is empty", () => {
    const { container } = render(
      <>
        <PayChangesBanner
          changes={{
            count: 0,
            previous_period: null,
            first_payslip: 0,
            percent: 20,
            min_cents: 50_000,
            employees: [],
          }}
        />
        <SharedBankAccountsBanner
          shared={{ count: 0, pending_hashes: 0, groups: [] }}
        />
        <MissingTinBanner missing={{ count: 0, employees: [] }} />
        <NoAttendanceBanner none={{ count: 0, employees: [] }} />
      </>,
    );

    expect(container).toBeEmptyDOMElement();
  });
});
