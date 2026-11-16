import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { BulkActionsBar } from "@/features/employees/components/bulk-actions-bar";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));

/** Someone's sign-in state, as the list row says it. */
type Row = {
  public_id: string;
  email: string | null;
  phone: string | null;
  sign_in: "none" | "invited" | "active" | "inactive" | "suspended";
  status: string;
};

const person = (n: number, over: Partial<Row> = {}): Row => ({
  public_id: `emp-${n}`,
  email: `p${n}@acme.et`,
  phone: null,
  sign_in: "none",
  status: "confirmed",
  ...over,
});

const manyIds = (n: number) => Array.from({ length: n }, (_, i) => `emp-${i}`);

/** One person on screen for each id. */
const everyone = (ids: string[]): Row[] =>
  ids.map((id) => ({ ...person(0), public_id: id }));

function me(permissions: string[]) {
  server.use(
    http.get("*/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        permissions,
        tenant: null,
      }),
    ),
  );
}

function page<T>(rows: T[]) {
  return {
    data: rows,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
    links: { first: "", last: "", prev: null, next: null },
  };
}

function renderBar(
  selectedIds: string[],
  people: Row[] = [],
  onComplete = vi.fn(),
) {
  server.use(
    http.get("*/api/v1/organization/departments", () =>
      HttpResponse.json(page([])),
    ),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(page([])),
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  render(
    <BulkActionsBar
      selectedIds={selectedIds}
      people={people}
      onComplete={onComplete}
    />,
    { wrapper: Wrapper },
  );
  return { onComplete };
}

const answer = (over: Record<string, number> = {}) =>
  HttpResponse.json(
    {
      message: "Queued",
      created: 0,
      active: 0,
      in_person: 0,
      email_in_use: 0,
      batch: null,
      queued: 0,
      ...over,
    },
    { status: 202 },
  );

const BUTTON = { name: "Give a way to sign in" } as const;

describe("bulk action: give a way to sign in", () => {
  it("is hidden from someone who may not invite", async () => {
    me(["employee.viewAny", "employee.update"]);
    renderBar(["emp-1"]);

    expect(await screen.findByText("1 selected")).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.queryByRole("button", BUTTON)).not.toBeInTheDocument(),
    );
  });

  it("is hidden from someone who may not edit employees", async () => {
    me(["users.invite"]);
    renderBar(["emp-1"]);

    expect(await screen.findByText("1 selected")).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.queryByRole("button", BUTTON)).not.toBeInTheDocument(),
    );
  });

  it("says what will happen, posts the selected ids on confirm and shows the totals", async () => {
    me(["users.invite", "employee.update"]);
    const bodies: unknown[] = [];
    server.use(
      http.post("*/api/v1/employees/logins", async ({ request }) => {
        bodies.push(await request.json());
        return answer({ created: 2, queued: 2, active: 1, email_in_use: 1 });
      }),
    );
    const { onComplete } = renderBar(
      ["emp-1", "emp-2", "emp-3"],
      [person(1), person(2), person(3)],
    );

    fireEvent.click(await screen.findByRole("button", BUTTON));
    expect(
      screen.getByText(/people with an email get an invitation by email/i),
    ).toBeInTheDocument();
    expect(
      screen.getByText("This covers 3 selected people."),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    expect(
      await screen.findByText(
        "Logins created: 2. Invitations queued: 2. Already able to sign in: 1. Need a code in person: 0. Email already used by another login: 1.",
      ),
    ).toBeInTheDocument();
    expect(bodies).toEqual([{ employee_ids: ["emp-1", "emp-2", "emp-3"] }]);
    // Nobody needs a code in person, so no printing is offered.
    expect(screen.queryByRole("button", { name: /code sheet/i })).toBeNull();
    expect(onComplete).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "Done" }));
    expect(onComplete).toHaveBeenCalledTimes(1);
  });

  it("sends 450 people in slices of 200 and adds the answers up", async () => {
    me(["users.invite", "employee.update"]);
    const sizes: number[] = [];
    server.use(
      http.post("*/api/v1/employees/logins", async ({ request }) => {
        const body = (await request.json()) as { employee_ids: string[] };
        sizes.push(body.employee_ids.length);
        return answer({ created: body.employee_ids.length, queued: 1 });
      }),
    );
    renderBar(manyIds(450), everyone(manyIds(450)));

    fireEvent.click(await screen.findByRole("button", BUTTON));
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    expect(
      await screen.findByText(/Logins created: 450\. Invitations queued: 3\./),
    ).toBeInTheDocument();
    expect(sizes).toEqual([200, 200, 50]);
  });

  it("reports what went when a slice fails, and a retry sends only the rest", async () => {
    me(["users.invite", "employee.update"]);
    const sizes: number[] = [];
    let call = 0;
    server.use(
      http.post("*/api/v1/employees/logins", async ({ request }) => {
        const body = (await request.json()) as { employee_ids: string[] };
        call += 1;
        if (call === 2) {
          return HttpResponse.json({ detail: "Try later." }, { status: 500 });
        }
        sizes.push(body.employee_ids.length);
        return answer({ created: body.employee_ids.length });
      }),
    );
    const { onComplete } = renderBar(manyIds(450), everyone(manyIds(450)));

    fireEvent.click(await screen.findByRole("button", BUTTON));
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    expect(
      await screen.findByText(/Sent 200 of 450\. 250 not sent yet\./),
    ).toBeInTheDocument();
    expect(onComplete).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "Send the rest" }));

    expect(
      await screen.findByText(/Logins created: 450\./),
    ).toBeInTheDocument();
    // 200 went first, the second slice failed, then the 250 left went as 200 + 50.
    expect(sizes).toEqual([200, 200, 50]);
  });

  it("ignores a second press while a run is going", async () => {
    me(["users.invite", "employee.update"]);
    let posts = 0;
    server.use(
      http.post("*/api/v1/employees/logins", async () => {
        posts += 1;
        await new Promise((r) => setTimeout(r, 50));
        return answer({ created: 1 });
      }),
    );
    renderBar(["emp-1"], [person(1)]);

    fireEvent.click(await screen.findByRole("button", BUTTON));
    const confirm = screen.getByRole("button", { name: "Confirm" });
    fireEvent.click(confirm);
    fireEvent.click(confirm);

    await screen.findByText(/Logins created: 1\./);
    expect(posts).toBe(1);
  });

  it("offers a printed code only when someone needs one, and only for people with no email and no phone", async () => {
    me([
      "users.invite",
      "employee.update",
      "users.issueActivationCode",
      "users.issueStartingPassword",
    ]);
    server.use(
      http.post("*/api/v1/employees/logins", () =>
        answer({ created: 1, queued: 1, in_person: 2 }),
      ),
    );
    renderBar(
      ["emp-1", "emp-2", "emp-3", "emp-4"],
      [
        person(1),
        person(2, { email: null, phone: null }),
        person(3, { email: " ", phone: "", sign_in: "invited" }),
        person(4, { email: null, phone: null, sign_in: "active" }),
      ],
    );

    fireEvent.click(await screen.findByRole("button", BUTTON));
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    const sheet = await screen.findByRole("button", {
      name: "Print a code sheet",
    });
    // emp-2 and emp-3 only: emp-1 has an email, emp-4 can already sign in.
    fireEvent.click(sheet);
    expect(
      await screen.findByText("Activation codes for 2 people"),
    ).toBeInTheDocument();
  });

  it("posts only current staff who cannot sign in, and says who is left out", async () => {
    me(["users.invite", "employee.update"]);
    const bodies: { employee_ids: string[] }[] = [];
    server.use(
      http.post("*/api/v1/employees/logins", async ({ request }) => {
        bodies.push((await request.json()) as { employee_ids: string[] });
        return answer({ created: 2 });
      }),
    );
    renderBar(
      ["emp-1", "emp-2", "emp-3", "emp-4", "emp-5", "emp-6"],
      [
        person(1),
        person(2, { sign_in: "invited" }),
        person(3, { status: "resigned" }),
        person(4, { sign_in: "active" }),
        person(5, { sign_in: "suspended" }),
        // emp-6 is not on screen at all.
      ],
    );

    fireEvent.click(await screen.findByRole("button", BUTTON));
    expect(
      screen.getByText("This covers 2 selected people."),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "4 of the selected people can already sign in or no longer work here, and are left out.",
      ),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await screen.findByText(/Logins created: 2\./);
    expect(bodies).toEqual([{ employee_ids: ["emp-1", "emp-2"] }]);
  });

  it("says so and keeps Confirm off when nobody in the selection qualifies", async () => {
    me(["users.invite", "employee.update"]);
    renderBar(
      ["emp-1", "emp-2"],
      [person(1, { status: "terminated" }), person(2, { sign_in: "active" })],
    );

    fireEvent.click(await screen.findByRole("button", BUTTON));

    expect(
      screen.getByText("Nobody in the selection needs a way to sign in."),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Confirm" })).toBeDisabled();
  });

  it("clears the selection when closed after a partial run", async () => {
    me(["users.invite", "employee.update"]);
    let call = 0;
    server.use(
      http.post("*/api/v1/employees/logins", () => {
        call += 1;
        return call === 1
          ? answer({ created: 200 })
          : HttpResponse.json({ detail: "Try later." }, { status: 500 });
      }),
    );
    const { onComplete } = renderBar(manyIds(450), everyone(manyIds(450)));

    fireEvent.click(await screen.findByRole("button", BUTTON));
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await screen.findByText(/Sent 200 of 450\./);
    expect(onComplete).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(onComplete).toHaveBeenCalledTimes(1);
  });

  it("does not call onComplete when closed before anything was sent", async () => {
    me(["users.invite", "employee.update"]);
    const { onComplete } = renderBar(["emp-1"], [person(1)]);

    fireEvent.click(await screen.findByRole("button", BUTTON));
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));

    expect(onComplete).not.toHaveBeenCalled();
  });

  it("refreshes the employees list after a send", async () => {
    me(["users.invite", "employee.update"]);
    server.use(
      http.post("*/api/v1/employees/logins", () => answer({ created: 1 })),
    );
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    });
    const invalidate = vi.spyOn(queryClient, "invalidateQueries");
    server.use(
      http.get("*/api/v1/organization/departments", () =>
        HttpResponse.json(page([])),
      ),
      http.get("*/api/v1/organization/branches", () =>
        HttpResponse.json(page([])),
      ),
    );
    render(
      <QueryClientProvider client={queryClient}>
        <CalendarProvider>
          <BulkActionsBar
            selectedIds={["emp-1"]}
            people={[person(1)]}
            onComplete={vi.fn()}
          />
        </CalendarProvider>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole("button", BUTTON));
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await screen.findByText(/Logins created: 1\./);

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["employees"] });
  });
});
