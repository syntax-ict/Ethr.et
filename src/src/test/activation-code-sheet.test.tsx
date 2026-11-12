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

function renderPanel(autoSend = false) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MigrationLogins batchId={BATCH} autoSend={autoSend} />
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
) => ({
  employee_public_id: id,
  name,
  email:
    channel === "email" ? `${name.split(" ")[0].toLowerCase()}@acme.et` : null,
  phone: null,
  channel,
  invited,
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
