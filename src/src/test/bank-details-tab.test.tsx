import { describe, it, expect } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
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

  it("shows the masked account number the resource actually returns", async () => {
    // Regression: the row read `account_number`, which BankDetailResource
    // deliberately never sends — it sends `account_number_masked`. The one
    // line that tells HR *which* account salary goes to rendered blank.
    server.use(http.get(BANK_URL, () => HttpResponse.json([buildBank()])));
    renderTab();

    expect(await screen.findByText("*********6789")).toBeInTheDocument();
  });
});

describe("<BankDetailsTab> delete", () => {
  it("names the delete control after the account it removes", async () => {
    // Regression: an icon-only button with no accessible name, announced to a
    // screen reader as just "button" on every row.
    server.use(http.get(BANK_URL, () => HttpResponse.json([buildBank()])));
    renderTab();

    expect(
      await screen.findByRole("button", {
        name: "Delete Commercial Bank of Ethiopia",
      }),
    ).toBeInTheDocument();
  });
});

/** Opens the add dialog and fills the two required fields by typing. */
async function fillNewAccount(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole("button", { name: /Add/ }));
  const dialog = await screen.findByRole("dialog");
  await user.type(within(dialog).getByLabelText(/Bank Name/), "Dashen Bank");
  await user.type(
    within(dialog).getByLabelText(/Account Number/),
    "2000987654321",
  );
  return dialog;
}

function capturePost() {
  const bodies: Array<Record<string, unknown>> = [];
  server.use(
    http.post(BANK_URL, async ({ request }) => {
      bodies.push((await request.json()) as Record<string, unknown>);
      return HttpResponse.json(buildBank(), { status: 201 });
    }),
  );
  return bodies;
}

describe("<BankDetailsTab> add", () => {
  it("sends only the fields StoreBankDetailRequest accepts", async () => {
    // Regression: the form had an "Account Holder Name" field. There is no
    // such column and the FormRequest has no such rule, so `validated()`
    // dropped it and HR was told "Bank details added" about a value that was
    // never stored.
    server.use(http.get(BANK_URL, () => HttpResponse.json([])));
    const bodies = capturePost();

    const user = userEvent.setup();
    renderTab();
    const dialog = await fillNewAccount(user);

    expect(
      within(dialog).queryByLabelText(/Account Holder/),
    ).not.toBeInTheDocument();

    await user.click(within(dialog).getByRole("button", { name: /^Add$/ }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(Object.keys(bodies[0]).sort()).toEqual([
      "account_number",
      "bank_name",
      "branch_name",
      "is_primary",
    ]);
  });

  it("does not make a second account the salary account unless asked", async () => {
    // Regression: `is_primary` was hard-wired to true with no control, and
    // storing a primary demotes the old one. Adding a second account silently
    // redirected the next payroll bank export into it.
    server.use(http.get(BANK_URL, () => HttpResponse.json([buildBank()])));
    const bodies = capturePost();

    const user = userEvent.setup();
    renderTab();
    await screen.findByText("Commercial Bank of Ethiopia");
    const dialog = await fillNewAccount(user);

    expect(
      within(dialog).getByRole("checkbox", {
        name: "Pay salary into this account",
      }),
    ).not.toBeChecked();

    await user.click(within(dialog).getByRole("button", { name: /^Add$/ }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0].is_primary).toBe(false);
  });

  it("makes a second account the salary account when the box is ticked", async () => {
    server.use(http.get(BANK_URL, () => HttpResponse.json([buildBank()])));
    const bodies = capturePost();

    const user = userEvent.setup();
    renderTab();
    await screen.findByText("Commercial Bank of Ethiopia");
    const dialog = await fillNewAccount(user);

    await user.click(
      within(dialog).getByRole("checkbox", {
        name: "Pay salary into this account",
      }),
    );
    await user.click(within(dialog).getByRole("button", { name: /^Add$/ }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0].is_primary).toBe(true);
  });

  it("makes the first account the salary account by default", async () => {
    server.use(http.get(BANK_URL, () => HttpResponse.json([])));
    const bodies = capturePost();

    const user = userEvent.setup();
    renderTab();
    await screen.findByText("No bank accounts");
    const dialog = await fillNewAccount(user);
    await user.click(within(dialog).getByRole("button", { name: /^Add$/ }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0].is_primary).toBe(true);
  });
});
