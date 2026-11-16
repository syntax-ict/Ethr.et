import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationLogins } from "@/features/onboarding/v2/components/migration-logins";
import { commitMigration } from "@/features/onboarding/v2/api";

/**
 * Mass activation (One employee identity, PR 9; owner decisions
 * 2026-11-10): a list is added with what its new people arrive as, the
 * people an invitation can reach are invited without a second button, and
 * the rest get a printed slip each with a one-time code.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const BATCH = "01JBQ8Z6ZK3M9V2X7Y4W5T6R8S";
const INVITATIONS = "01JBQ8Z6ZK3M9V2X7Y4W5T6R9A";
const ABEBE = "01JBQ8Z6ZK3M9V2X7Y4W5T6R81";
const HANNA = "01JBQ8Z6ZK3M9V2X7Y4W5T6R82";
const SARA = "01JBQ8Z6ZK3M9V2X7Y4W5T6R83";
const DAWIT = "01JBQ8Z6ZK3M9V2X7Y4W5T6R84";

function renderPanel(autoSend = false, inviteMarked = false) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MigrationLogins
        batchId={BATCH}
        autoSend={autoSend}
        inviteMarked={inviteMarked}
      />
    </QueryClientProvider>,
  );
}

function me(permissions: string[]) {
  return http.get("*/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "tenant_admin" },
      permissions,
      tenant: null,
    }),
  );
}

const person = (
  id: string,
  name: string,
  channel: "email" | "in_person",
  invited = false,
  asked: boolean | null = null,
) => ({
  employee_public_id: id,
  name,
  email:
    channel === "email" ? `${name.split(" ")[0].toLowerCase()}@acme.et` : null,
  phone: null,
  channel,
  invited,
  asked,
});

/** One person to invite, one already invited, two no invitation can reach. */
const LOGINS = {
  without_login: [
    person(ABEBE, "Abebe Kebede", "email"),
    person(DAWIT, "Dawit Alemu", "email", true),
    person(HANNA, "Hanna Girma", "in_person"),
    person(SARA, "Sara Tesfaye", "in_person"),
  ],
  hire_dates_defaulted: 0,
};

function logins(body: typeof LOGINS = LOGINS) {
  return http.get(`*/api/v1/onboarding/migration/batches/${BATCH}/logins`, () =>
    HttpResponse.json(body),
  );
}

/** The invitation endpoints, recording who each request named. */
function invitations() {
  const posted: { employee_ids: string[] }[] = [];
  server.use(
    http.post("*/api/v1/employees/logins", async ({ request }) => {
      posted.push((await request.json()) as { employee_ids: string[] });
      return HttpResponse.json(
        {
          message: "Queued",
          created: 1,
          active: 0,
          in_person: 0,
          email_in_use: 0,
          batch: INVITATIONS,
          queued: 1,
        },
        { status: 202 },
      );
    }),
    http.get(`*/api/v1/users/invitations/${INVITATIONS}`, () =>
      HttpResponse.json({
        batch: INVITATIONS,
        total: 1,
        queued: 1,
        sent: 0,
        failed: 0,
        skipped: 0,
        failures: [],
      }),
    ),
  );
  return posted;
}

describe("invitations by default", () => {
  it("invites the people an invitation can reach, once, without a button", async () => {
    server.use(me(["users.invite", "employee.create"]), logins());
    const posted = invitations();
    const view = renderPanel(true);

    await waitFor(() => expect(posted).toHaveLength(1));
    // Not the two a code is for, and not the one already invited.
    expect(posted[0]).toEqual({ employee_ids: [ABEBE] });
    expect(
      await screen.findByText(/Logins created: 1\. Invitations queued: 1\./),
    ).toBeInTheDocument();

    // Looking again sends nothing more.
    view.rerender(
      <QueryClientProvider
        client={
          new QueryClient({ defaultOptions: { queries: { retry: false } } })
        }
      >
        <MigrationLogins batchId={BATCH} autoSend />
      </QueryClientProvider>,
    );
    await screen.findByText("Hanna Girma");
    expect(posted).toHaveLength(1);
  });

  it("sends nothing by itself when the reviewer unticked it", async () => {
    server.use(me(["users.invite", "employee.create"]), logins());
    const posted = invitations();
    renderPanel(false);

    expect(
      await screen.findByRole("button", { name: "Send invitations" }),
    ).toBeInTheDocument();
    expect(posted).toHaveLength(0);
  });

  it("sends nothing by itself for someone who may not invite, or when nobody can be reached", async () => {
    const posted = invitations();
    server.use(me(["employee.create"]), logins());
    const first = renderPanel(true);
    await screen.findByText("Abebe Kebede");
    expect(posted).toHaveLength(0);
    first.unmount();

    server.use(
      me(["users.invite"]),
      logins({
        without_login: [person(HANNA, "Hanna Girma", "in_person")],
        hire_dates_defaulted: 0,
      }),
    );
    renderPanel(true);
    await screen.findByText("Hanna Girma");
    expect(posted).toHaveLength(0);
  });
});

describe("a list's arrival status", () => {
  const done = {
    message: "ok",
    status: "committed",
    remaining: 0,
    totals: { created: 2, merged: 0, updated: 0, skipped: 0, deferred: 0 },
    without_login: [],
    hire_dates_defaulted: 0,
  };

  it("is sent with the commit, and nothing is sent when nothing was chosen", async () => {
    const bodies: unknown[] = [];
    server.use(
      http.post(
        "*/onboarding/migration/batches/:id/commit",
        async ({ request }) => {
          const text = await request.text();
          bodies.push(text === "" ? null : JSON.parse(text));
          return HttpResponse.json(done);
        },
      ),
    );

    await commitMigration("01HZBATCH", undefined, "confirmed");
    await commitMigration("01HZBATCH");

    expect(bodies).toEqual([{ status: "confirmed" }, null]);
  });
});

describe("a sheet of activation codes", () => {
  const SHEET = {
    batch: "01JBQ8Z6ZK3M9V2X7Y4W5T6RAA",
    expires_at: "2026-11-15T08:00:00+00:00",
    issued: 1,
    codes: [
      {
        employee_public_id: HANNA,
        name: "Hanna Girma",
        employee_code: "EMP-0002",
        code: "ABCD-EFGH-JKMN",
        activation_url:
          "https://acme.ethr.et/login/activate?tenant=acme#c=ABCDEFGHJKMN&e=EMP-0002",
      },
    ],
    refused: [
      {
        employee_public_id: SARA,
        name: "Sara Tesfaye",
        reason: "identifier",
        detail:
          "This employee would have no way to sign in. Turn on employee code sign-in in settings first.",
      },
    ],
  };

  it("is offered only to someone who may issue codes, and only for people no invitation reaches", async () => {
    server.use(me(["users.invite"]), logins());
    const first = renderPanel();
    await screen.findByText("Hanna Girma");
    expect(
      screen.queryByRole("button", { name: "Print a code sheet" }),
    ).not.toBeInTheDocument();
    first.unmount();

    server.use(
      me(["users.issueActivationCode"]),
      logins({
        without_login: [person(ABEBE, "Abebe Kebede", "email")],
        hire_dates_defaulted: 0,
      }),
    );
    renderPanel();
    await screen.findByText("Abebe Kebede");
    expect(
      screen.queryByRole("button", { name: "Print a code sheet" }),
    ).not.toBeInTheDocument();
  });

  it("takes the undertaking and the HR password, then shows each slip once, and who got none and why", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      me(["users.issueActivationCode"]),
      logins(),
      http.post("*/api/v1/employees/activation-codes", async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(SHEET, { status: 201 });
      }),
    );
    renderPanel();

    await userEvent.click(
      await screen.findByRole("button", { name: "Print a code sheet" }),
    );
    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText("Activation codes for 2 people"),
    ).toBeInTheDocument();

    const make = within(dialog).getByRole("button", { name: "Make the codes" });
    expect(make).toBeDisabled();

    await userEvent.type(
      within(dialog).getByLabelText("Your password"),
      "my-own-password",
    );
    expect(make).toBeDisabled();
    await userEvent.click(
      within(dialog).getByLabelText(/I will check each person's identity/),
    );
    await userEvent.selectOptions(
      within(dialog).getByLabelText("The codes work for"),
      "168",
    );
    await userEvent.click(make);

    await waitFor(() =>
      expect(posted).toEqual({
        employee_ids: [HANNA, SARA],
        attested: true,
        hours: 168,
        current_password: "my-own-password",
      }),
    );

    // The slip: who it is for, their employee code, the code, and a QR.
    expect(await within(dialog).findByTestId("sheet-code")).toHaveTextContent(
      "ABCD-EFGH-JKMN",
    );
    expect(within(dialog).getByText("EMP-0002")).toBeInTheDocument();
    expect(within(dialog).getByTitle("Activation QR code")).toBeInTheDocument();
    expect(within(dialog).getByText("No code for 1")).toBeInTheDocument();
    expect(
      within(dialog).getByText(/Turn on employee code sign-in/),
    ).toBeInTheDocument();
    // The HR password does not stay in the page once it has been checked.
    expect(within(dialog).queryByLabelText("Your password")).toBeNull();

    // Closed, the codes are gone: opening again asks afresh.
    await userEvent.click(within(dialog).getByRole("button", { name: "Done" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    expect(screen.queryByText("ABCD-EFGH-JKMN")).toBeNull();
  });

  it("says plainly when no code was made, and where to turn on employee code sign-in", async () => {
    server.use(
      me(["users.issueActivationCode"]),
      logins(),
      http.post("*/api/v1/employees/activation-codes", () =>
        HttpResponse.json(
          {
            batch: null,
            expires_at: SHEET.expires_at,
            issued: 0,
            codes: [],
            refused: [
              {
                ...SHEET.refused[0],
                employee_public_id: HANNA,
                name: "Hanna Girma",
              },
              SHEET.refused[0],
            ],
          },
          { status: 201 },
        ),
      ),
    );
    renderPanel();

    await userEvent.click(
      await screen.findByRole("button", { name: "Print a code sheet" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText("Your password"), "x");
    await userEvent.click(
      within(dialog).getByLabelText(/I will check each person's identity/),
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Make the codes" }),
    );

    expect(
      await within(dialog).findByText(/No codes were made/),
    ).toBeInTheDocument();
    expect(within(dialog).getByText("No code for 2")).toBeInTheDocument();
    expect(
      within(dialog).getByRole("link", {
        name: /turn on employee code sign-in under Access/,
      }),
    ).toHaveAttribute("href", "/setup/guided");
    // Nothing to print, and no advice about slips that do not exist.
    expect(
      within(dialog).getByRole("button", { name: "Print" }),
    ).toBeDisabled();
    expect(within(dialog).queryByText(/Hand each slip/)).toBeNull();
  });

  it("says what was wrong when the request is refused", async () => {
    server.use(
      me(["users.issueActivationCode"]),
      logins(),
      http.post("*/api/v1/employees/activation-codes", () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "The password is incorrect.",
            errors: { current_password: ["The password is incorrect."] },
          },
          { status: 422 },
        ),
      ),
    );
    renderPanel();

    await userEvent.click(
      await screen.findByRole("button", { name: "Print a code sheet" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText("Your password"), "x");
    await userEvent.click(
      within(dialog).getByLabelText(/I will check each person's identity/),
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Make the codes" }),
    );

    expect(await within(dialog).findByRole("alert")).toHaveTextContent(
      "The password is incorrect.",
    );
    expect(within(dialog).queryByTestId("sheet-code")).toBeNull();
  });
});

describe("invitations in slices", () => {
  // The server takes 1,000 ids a request and a 2,000-person list got a 422 on
  // the automatic send (2026-11-16): the browser sends 200 at a time.
  const many = (n: number) => ({
    without_login: Array.from({ length: n }, (_, i) =>
      person(
        `01JBQ8Z6ZK3M9V2X7Y4W5T${String(i).padStart(4, "0")}`,
        `Person ${i}`,
        "email",
      ),
    ),
    hire_dates_defaulted: 0,
  });

  /** Answers every slice, naming one invitation batch each; `failOn` is a request number (1-based) that answers 500 once. */
  function slices(failOn?: number, slowFirst = 0) {
    const posted: string[][] = [];
    let failed = false;
    server.use(
      http.post("*/api/v1/employees/logins", async ({ request }) => {
        const body = (await request.json()) as { employee_ids: string[] };
        posted.push(body.employee_ids);
        if (slowFirst && posted.length === 1) {
          await new Promise((resolve) => setTimeout(resolve, slowFirst));
        }
        if (posted.length === failOn && !failed) {
          failed = true;
          return HttpResponse.json(
            { status: 500, title: "Server Error" },
            { status: 500 },
          );
        }
        return HttpResponse.json(
          {
            message: "Queued",
            created: body.employee_ids.length,
            active: 0,
            in_person: 0,
            email_in_use: 0,
            batch: `01JBQ8Z6ZK3M9V2X7Y4W5TB${String(posted.length).padStart(3, "0")}`,
            queued: body.employee_ids.length,
          },
          { status: 202 },
        );
      }),
      http.get("*/api/v1/users/invitations/:batch", ({ params }) =>
        HttpResponse.json({
          batch: params.batch,
          total: 1,
          queued: 1,
          sent: 0,
          failed: 0,
          skipped: 0,
          failures: [],
        }),
      ),
    );
    return posted;
  }

  it("sends 450 people as 200, 200 and 50, none twice, and adds the results up", async () => {
    server.use(me(["users.invite", "employee.create"]), logins(many(450)));
    const posted = slices();
    renderPanel(true);

    expect(
      await screen.findByText(
        "Logins created: 450. Invitations queued: 450. To activate in person: 0.",
      ),
    ).toBeInTheDocument();
    expect(posted.map((ids) => ids.length)).toEqual([200, 200, 50]);
    expect(new Set(posted.flat()).size).toBe(450);
    // One progress card for each slice's batch.
    await waitFor(() =>
      expect(screen.getAllByText("Invitations are being sent")).toHaveLength(3),
    );
    expect(
      screen.queryByRole("button", { name: "Send invitations" }),
    ).not.toBeInTheDocument();
  });

  it("keeps what the first slice did when the second fails, and then sends only the rest", async () => {
    server.use(me(["users.invite", "employee.create"]), logins(many(450)));
    const posted = slices(2);
    renderPanel(true);

    expect(
      await screen.findByText(
        "Logins created: 200. Invitations queued: 200. To activate in person: 0.",
      ),
    ).toBeInTheDocument();
    expect(posted.map((ids) => ids.length)).toEqual([200, 200]);

    await userEvent.click(
      await screen.findByRole("button", { name: "Send invitations" }),
    );

    await waitFor(() => expect(posted).toHaveLength(4));
    // The 250 that were not sent (in two slices), and none of the 200 that were.
    expect(posted.slice(2).map((ids) => ids.length)).toEqual([200, 50]);
    expect(posted.slice(2).flat()).toEqual(
      many(450)
        .without_login.slice(200)
        .map((p) => p.employee_public_id),
    );
    expect(
      await screen.findByText(
        "Logins created: 450. Invitations queued: 450. To activate in person: 0.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Send invitations" }),
    ).not.toBeInTheDocument();
  });

  it("does not send anyone twice when the button is pressed during the run", async () => {
    server.use(me(["users.invite", "employee.create"]), logins(many(450)));
    const posted = slices(undefined, 600);
    renderPanel(true);

    // The automatic send is in its first, slow request: the button is there,
    // disabled for the whole run, and a press does nothing.
    const button = await screen.findByRole("button", {
      name: "Send invitations",
    });
    await waitFor(() => expect(button).toBeDisabled());
    await userEvent.click(button);

    await screen.findByText(
      "Logins created: 450. Invitations queued: 450. To activate in person: 0.",
    );
    expect(posted.map((ids) => ids.length)).toEqual([200, 200, 50]);
    expect(new Set(posted.flat()).size).toBe(450);
  });

  it("sends in slices from the button too", async () => {
    server.use(me(["users.invite", "employee.create"]), logins(many(450)));
    const posted = slices();
    renderPanel(false);

    await userEvent.click(
      await screen.findByRole("button", { name: "Send invitations" }),
    );

    await waitFor(() => expect(posted).toHaveLength(3));
    expect(posted.map((ids) => ids.length)).toEqual([200, 200, 50]);
  });
});

describe("a list that says, person by person, who is invited", () => {
  const BATCHES = {
    // Sara is marked yes and Hanna no; Abebe and Dawit are left to the tick.
    without_login: [
      person(ABEBE, "Abebe Kebede", "email"),
      person(DAWIT, "Dawit Alemu", "email", false, true),
      person(HANNA, "Hanna Girma", "email", false, false),
      person(SARA, "Sara Tesfaye", "in_person", false, true),
    ],
    hire_dates_defaulted: 0,
  };

  it("invites the people marked yes by themselves when the tick is off, and nobody else", async () => {
    server.use(me(["users.invite", "employee.create"]), logins(BATCHES));
    const posted = invitations();
    renderPanel(false, true);

    await waitFor(() => expect(posted).toHaveLength(1));
    // Dawit only: Abebe waits for the button, Hanna is marked no, and Sara
    // can only be activated in person.
    expect(posted[0].employee_ids).toEqual([DAWIT]);
  });

  it("keeps the button for a blank cell after the automatic send, sends only that person, and then drops it", async () => {
    const CHALTU = "01JBQ8Z6ZK3M9V2X7Y4W5T6R85";
    server.use(
      me(["users.invite", "employee.create"]),
      logins({
        without_login: [
          person(DAWIT, "Dawit Alemu", "email", false, true),
          person(CHALTU, "Chaltu Blank Email", "email"),
          person(HANNA, "Hanna Girma", "email", false, false),
        ],
        hire_dates_defaulted: 0,
      }),
    );
    const posted = invitations();
    renderPanel(false, true);

    await waitFor(() => expect(posted).toHaveLength(1));
    expect(posted[0].employee_ids).toEqual([DAWIT]);

    await userEvent.click(
      await screen.findByRole("button", { name: "Send invitations" }),
    );
    await waitFor(() => expect(posted).toHaveLength(2));
    expect(posted[1].employee_ids).toEqual([CHALTU]);
    // Hanna, marked no, is in neither request.
    expect(posted.flat().flatMap((p) => p.employee_ids)).not.toContain(HANNA);
    await waitFor(() =>
      expect(
        screen.queryByRole("button", { name: "Send invitations" }),
      ).not.toBeInTheDocument(),
    );
  });

  it("does not keep the button for someone the server still reports as not invited after a send", async () => {
    server.use(
      me(["users.invite", "employee.create"]),
      logins({
        without_login: [person(ABEBE, "Abebe Kebede", "email")],
        hire_dates_defaulted: 0,
      }),
      http.post("*/api/v1/employees/logins", () =>
        HttpResponse.json(
          {
            message: "Queued",
            created: 0,
            active: 0,
            in_person: 0,
            email_in_use: 1,
            batch: null,
            queued: 0,
          },
          { status: 202 },
        ),
      ),
    );
    renderPanel(false);

    await userEvent.click(
      await screen.findByRole("button", { name: "Send invitations" }),
    );

    expect(
      await screen.findByText(/Email already used by another login: 1/),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Send invitations" }),
    ).not.toBeInTheDocument();
  });

  it("invites everyone an invitation can reach except the people marked no when the tick is on", async () => {
    server.use(me(["users.invite", "employee.create"]), logins(BATCHES));
    const posted = invitations();
    renderPanel(true, true);

    await waitFor(() => expect(posted).toHaveLength(1));
    expect(posted[0].employee_ids).toEqual([ABEBE, DAWIT]);
  });

  it("leaves the people marked no out of the button, and says why they are in the table", async () => {
    server.use(me(["users.invite", "employee.create"]), logins(BATCHES));
    const posted = invitations();
    renderPanel(false, true);

    // The automatic send went first; the button sends the rest, as before.
    await waitFor(() => expect(posted).toHaveLength(1));
    expect(
      await screen.findByText(/this list did not ask to invite them/),
    ).toBeInTheDocument();
    expect(screen.getByText("Hanna Girma")).toBeInTheDocument();
  });

  it("sends the button's people, the ones marked no excepted", async () => {
    server.use(
      me(["users.invite", "employee.create"]),
      logins({
        without_login: [
          person(ABEBE, "Abebe Kebede", "email"),
          person(HANNA, "Hanna Girma", "email", false, false),
        ],
        hire_dates_defaulted: 0,
      }),
    );
    const posted = invitations();
    renderPanel(false);

    await userEvent.click(
      await screen.findByRole("button", { name: "Send invitations" }),
    );

    await waitFor(() => expect(posted).toHaveLength(1));
    expect(posted[0].employee_ids).toEqual([ABEBE]);
    expect(
      screen.getByText(/this list did not ask to invite them/),
    ).toBeInTheDocument();
  });

  it("offers no button when everyone is marked no", async () => {
    server.use(
      me(["users.invite", "employee.create"]),
      logins({
        without_login: [person(HANNA, "Hanna Girma", "email", false, false)],
        hire_dates_defaulted: 0,
      }),
    );
    const posted = invitations();
    renderPanel(true);

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Send invitations" }),
    ).not.toBeInTheDocument();
    expect(posted).toHaveLength(0);
  });

  it("sends nothing by itself when the list is only opened again, even with people marked yes", async () => {
    server.use(me(["users.invite", "employee.create"]), logins(BATCHES));
    const posted = invitations();
    renderPanel(false, false);

    expect(await screen.findByText("Dawit Alemu")).toBeInTheDocument();
    expect(posted).toHaveLength(0);
    expect(
      screen.getByRole("button", { name: "Send invitations" }),
    ).toBeEnabled();
  });
});
