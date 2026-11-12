import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { IdentitiesCard } from "@/features/employees/components/identities-card";
import { PossibleDuplicatesList } from "@/features/employees/components/possible-duplicates-list";

/**
 * What an employee is known as outside ETHR, and employees who may be one
 * person recorded twice (One employee identity, PR 11).
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const EMPLOYEE = "01JBQ8Z6ZK3M9V2X7Y4W5T6R81";
const ON_DEVICE = "01JBQ8Z6ZK3M9V2X7Y4W5T6RD1";
const IN_PAYROLL = "01JBQ8Z6ZK3M9V2X7Y4W5T6RD2";

function withClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

function me(permissions: string[]) {
  return http.get("*/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
      permissions,
      tenant: null,
    }),
  );
}

const IDENTITIES = {
  created_via: "device",
  identities: [
    {
      public_id: ON_DEVICE,
      kind: "device",
      source: "Front gate",
      source_type: "zkteco",
      identifier_type: "device_user_id",
      identifier_value: "14",
      verified: false,
      linked_at: "2026-11-01T08:00:00+00:00",
    },
    {
      public_id: IN_PAYROLL,
      kind: "other_system",
      source: null,
      source_type: "hr_system",
      identifier_type: "external_id",
      identifier_value: "P-1042",
      verified: true,
      linked_at: "2026-11-02T08:00:00+00:00",
    },
  ],
};

describe("<IdentitiesCard>", () => {
  it("says how the record was made and what the person is known as, in each place", async () => {
    server.use(
      me(["employee.view"]),
      http.get(`*/employees/${EMPLOYEE}/identities`, () =>
        HttpResponse.json(IDENTITIES),
      ),
    );
    withClient(<IdentitiesCard employeeId={EMPLOYEE} />);

    expect(
      await screen.findByText("Brought in from an attendance device"),
    ).toBeInTheDocument();

    const rows = screen.getAllByRole("listitem");
    expect(within(rows[0]).getByText("Front gate")).toBeInTheDocument();
    expect(within(rows[0]).getByText("14")).toBeInTheDocument();
    expect(within(rows[0]).getByText("Matched by ETHR")).toBeInTheDocument();
    // An id with no named source is still said to be from somewhere.
    expect(within(rows[1]).getByText("Another system")).toBeInTheDocument();
    expect(within(rows[1]).getByText("P-1042")).toBeInTheDocument();
    expect(within(rows[1]).getByText("Confirmed")).toBeInTheDocument();

    // Reading a profile is not editing it.
    expect(screen.queryByRole("button", { name: "Confirm" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Remove" })).toBeNull();
  });

  it("confirms a link ETHR inferred, and offers that only where it is not confirmed", async () => {
    const verified: string[] = [];
    server.use(
      me(["employee.view", "employee.update"]),
      http.get(`*/employees/${EMPLOYEE}/identities`, () =>
        HttpResponse.json(IDENTITIES),
      ),
      http.post(
        `*/employees/${EMPLOYEE}/identities/:identity/verify`,
        ({ params }) => {
          verified.push(String(params.identity));
          return HttpResponse.json({ verified: true });
        },
      ),
    );
    withClient(<IdentitiesCard employeeId={EMPLOYEE} />);

    const confirm = await screen.findAllByRole("button", { name: "Confirm" });
    expect(confirm).toHaveLength(1);
    await userEvent.click(confirm[0]);

    await waitFor(() => expect(verified).toEqual([ON_DEVICE]));
  });

  it("removes a link only after saying what removing it does", async () => {
    const removed: string[] = [];
    server.use(
      me(["employee.view", "employee.update"]),
      http.get(`*/employees/${EMPLOYEE}/identities`, () =>
        HttpResponse.json(IDENTITIES),
      ),
      http.delete(
        `*/employees/${EMPLOYEE}/identities/:identity`,
        ({ params }) => {
          removed.push(String(params.identity));
          return new HttpResponse(null, { status: 204 });
        },
      ),
    );
    withClient(<IdentitiesCard employeeId={EMPLOYEE} />);

    const remove = await screen.findAllByRole("button", { name: "Remove" });
    await userEvent.click(remove[0]);

    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText(
        /Front gate will no longer know this employee as 14\. Their record is not changed\./,
      ),
    ).toBeInTheDocument();
    expect(removed).toEqual([]);

    await userEvent.click(
      within(dialog).getByRole("button", { name: "Remove the link" }),
    );
    await waitFor(() => expect(removed).toEqual([ON_DEVICE]));
  });

  it("says so when nothing is linked, and when the record is older than the gateway", async () => {
    server.use(
      me(["employee.view"]),
      http.get(`*/employees/${EMPLOYEE}/identities`, () =>
        HttpResponse.json({ created_via: null, identities: [] }),
      ),
    );
    withClient(<IdentitiesCard employeeId={EMPLOYEE} />);

    expect(
      await screen.findByText(/How this record was made is not known/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "No attendance device or other system is linked to this employee yet.",
      ),
    ).toBeInTheDocument();
  });

  it("does not say nothing is linked when the links could not be read", async () => {
    server.use(
      me(["employee.view"]),
      http.get(`*/employees/${EMPLOYEE}/identities`, () =>
        HttpResponse.json(
          { title: "Server Error", status: 500 },
          { status: 500 },
        ),
      ),
    );
    withClient(<IdentitiesCard employeeId={EMPLOYEE} />);

    await waitFor(() =>
      expect(
        screen.queryByRole("button", { name: /retry|try again/i }),
      ).not.toBeNull(),
    );
    expect(
      screen.queryByText(
        "No attendance device or other system is linked to this employee yet.",
      ),
    ).toBeNull();
  });
});

describe("<PossibleDuplicatesList>", () => {
  const person = (
    id: string,
    name: string,
    code: string,
    via: string | null,
  ) => ({
    public_id: id,
    name,
    employee_code: code,
    status: "confirmed",
    department: "Finance",
    created_via: via,
    created_at: "2026-10-01T08:00:00+00:00",
  });

  it("shows each group with why its records are together, and a way to each profile", async () => {
    server.use(
      http.get("*/employees/possible-duplicates", () =>
        HttpResponse.json({
          groups: [
            {
              reasons: ["email", "phone"],
              employees: [
                person(
                  "01JBQ8Z6ZK3M9V2X7Y4W5T6RE1",
                  "Abebe Kebede",
                  "E-1",
                  "device",
                ),
                person(
                  "01JBQ8Z6ZK3M9V2X7Y4W5T6RE2",
                  "A. Kebede",
                  "E-2",
                  "import",
                ),
              ],
            },
            {
              reasons: ["badge_number"],
              employees: [
                person(
                  "01JBQ8Z6ZK3M9V2X7Y4W5T6RE3",
                  "Hanna Girma",
                  "E-3",
                  null,
                ),
                person("01JBQ8Z6ZK3M9V2X7Y4W5T6RE4", "Hanna G.", "E-4", null),
              ],
            },
          ],
          truncated: false,
        }),
      ),
    );
    withClient(<PossibleDuplicatesList />);

    expect(await screen.findByText("Same email")).toBeInTheDocument();
    expect(screen.getByText("Same phone number")).toBeInTheDocument();
    expect(screen.getByText("Same badge number")).toBeInTheDocument();

    expect(screen.getByRole("link", { name: "Abebe Kebede" })).toHaveAttribute(
      "href",
      "/employees/01JBQ8Z6ZK3M9V2X7Y4W5T6RE1",
    );
    expect(
      screen.getByText(/Brought in from an attendance device/),
    ).toBeInTheDocument();
    expect(screen.getByText(/Brought in from a file/)).toBeInTheDocument();
    expect(screen.queryByText(/There are more than these/)).toBeNull();
  });

  it("says there are none, and says when there are more than it shows", async () => {
    server.use(
      http.get("*/employees/possible-duplicates", () =>
        HttpResponse.json({ groups: [], truncated: false }),
      ),
    );
    const first = withClient(<PossibleDuplicatesList />);
    expect(
      await screen.findByText("No possible duplicates"),
    ).toBeInTheDocument();
    first.unmount();

    server.use(
      http.get("*/employees/possible-duplicates", () =>
        HttpResponse.json({
          groups: [
            {
              reasons: ["national_id"],
              employees: [
                person(
                  "01JBQ8Z6ZK3M9V2X7Y4W5T6RE1",
                  "Abebe Kebede",
                  "E-1",
                  null,
                ),
                person("01JBQ8Z6ZK3M9V2X7Y4W5T6RE2", "A. Kebede", "E-2", null),
              ],
            },
          ],
          truncated: true,
        }),
      ),
    );
    withClient(<PossibleDuplicatesList />);

    expect(await screen.findByText("Same national ID")).toBeInTheDocument();
    expect(screen.getByText(/There are more than these/)).toBeInTheDocument();
  });
});
