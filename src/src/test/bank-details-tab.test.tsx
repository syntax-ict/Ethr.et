import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { BankDetailsTab } from "@/features/employees/components/bank-details-tab";

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const BANK_URL = `*/api/v1/employees/${EMPLOYEE_ID}/bank-details`;

/** The shape `BankDetailResource` renders — nothing more. */
function buildBank(overrides: Record<string, unknown> = {}) {
  return {
    public_id: "01HZBANK00000000000000001",
    bank_name: "Commercial Bank of Ethiopia",
    branch_name: "Bole",
    account_number_masked: "*********6789",
    is_primary: true,
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
  return render(<BankDetailsTab employeeId={EMPLOYEE_ID} />, {
    wrapper: Wrapper,
  });
}

describe("<BankDetailsTab>", () => {
  it("lists the accounts the endpoint returns as a bare array", async () => {
    // Regression: the tab read `data.data`, but resources render unwrapped
    // (`JsonResource::withoutWrapping()`), so every account an HR user added
    // vanished behind "No bank accounts" the moment the list refetched.
    server.use(http.get(BANK_URL, () => HttpResponse.json([buildBank()])));
    renderTab();

    expect(
      await screen.findByText("Commercial Bank of Ethiopia"),
    ).toBeInTheDocument();
    expect(screen.queryByText("No bank accounts")).not.toBeInTheDocument();
  });
});
