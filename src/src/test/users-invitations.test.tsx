import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import UsersSettingsPage from "@/app/(dashboard)/settings/users/page";

/**
 * Invitations for many people at once (owner decisions, 2026-10-28): sent from
 * the server's queue a few a minute, so the users page shows where a send
 * stands, lists who has not activated, and says what became of each person's
 * last invitation.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

let search = "";
vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(search),
  useRouter: () => ({
    push: vi.fn(),
    replace: (url: string) => {
      search = url.split("?")[1] ?? "";
    },
    back: vi.fn(),
  }),
  usePathname: () => "/settings/users",
}));

const BATCH = "01JBQ8Z6ZK3M9V2X7Y4W5T6R8S";

const INVITED = {
  public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R81",
  email: "almaz@abay.et",
  username: null,
  phone: null,
  role: "employee",
  status: "invited",
  locale: "en",
  mfa_enabled: false,
  invited_at: "2026-10-27T08:00:00Z",
  activated_at: null,
  last_login_at: null,
  employee: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R82", name: "Almaz Tesfaye" },
  last_invitation: {
    channel: "email",
    status: "failed",
    reminder: false,
    queued_at: "2026-10-27T08:00:00Z",
    sent_at: null,
    held_until: null,
  },
  created_at: "2026-10-27T08:00:00Z",
};

function usersWith(lastInvitation: Record<string, unknown>) {
  return [
    {
      ...INVITED,
      last_invitation: { ...INVITED.last_invitation, ...lastInvitation },
    },
  ];
}

let userQueries: URLSearchParams[] = [];
let posted: unknown[] = [];

beforeEach(() => {
  search = "";
  userQueries = [];
  posted = [];

  server.use(
    http.get("*/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "tenant_admin" },
        permissions: ["users.viewAny", "users.invite"],
        tenant: null,
      }),
    ),
    http.get("*/api/v1/users", ({ request }) => {
      userQueries.push(new URL(request.url).searchParams);
      return HttpResponse.json({
        data: [INVITED],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        links: { next: null, prev: null },
      });
    }),
    http.post("*/api/v1/users/invitations", async ({ request }) => {
      posted.push(await request.json());
      return HttpResponse.json(
        {
          message: "Invitations are being sent.",
          batch: BATCH,
          queued: 1,
          held: 0,
          in_person: 0,
          skipped: 0,
        },
        { status: 202 },
      );
    }),
    http.get(`*/api/v1/users/invitations/${BATCH}`, () =>
      HttpResponse.json({
        batch: BATCH,
        total: 1,
        queued: 1,
        held: 0,
        held_until: null,
        daily_limit: 100,
        sent: 0,
        failed: 0,
        skipped: 0,
        failures: [],
      }),
    ),
  );
});

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <UsersSettingsPage />
    </QueryClientProvider>,
  );
}

describe("users page invitations", () => {
  it("says what became of an invited person's last invitation", async () => {
    renderPage();

    expect(
      await screen.findByText("Invitation could not be sent"),
    ).toBeInTheDocument();
  });

  it("says when a held invitation goes out", async () => {
    const future = new Date(Date.now() + 3 * 86_400_000).toISOString();
    server.use(
      http.get("*/api/v1/users", () =>
        HttpResponse.json({
          data: usersWith({ status: "queued", held_until: future }),
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
          links: { next: null, prev: null },
        }),
      ),
    );
    renderPage();

    expect(
      await screen.findByText(/^Invitation goes out on .+/),
    ).toBeInTheDocument();
    expect(screen.queryByText("Invitation on its way")).not.toBeInTheDocument();
  });

  it("still says on its way when nothing is held", async () => {
    server.use(
      http.get("*/api/v1/users", () =>
        HttpResponse.json({
          data: usersWith({ status: "queued", held_until: null }),
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
          links: { next: null, prev: null },
        }),
      ),
    );
    renderPage();

    expect(
      await screen.findByText("Invitation on its way"),
    ).toBeInTheDocument();
    expect(
      screen.queryByText(/Invitation goes out on/),
    ).not.toBeInTheDocument();
  });

  it("resends to everyone not activated and shows the send in progress", async () => {
    renderPage();
    const user = userEvent.setup();

    await user.click(
      await screen.findByRole("button", {
        name: /Resend to everyone not activated/,
      }),
    );

    expect(posted).toEqual([{ scope: "not_activated" }]);
    expect(
      await screen.findByText("Invitations are being sent"),
    ).toBeInTheDocument();
    expect(screen.getByText("0 of 1 sent")).toBeInTheDocument();
  });

  it("lists only the people who have not activated", async () => {
    const { rerender } = renderPage();
    const user = userEvent.setup();

    await user.click(
      await screen.findByRole("button", { name: "Not activated" }),
    );
    rerender(
      <QueryClientProvider client={new QueryClient()}>
        <UsersSettingsPage />
      </QueryClientProvider>,
    );

    await waitFor(() =>
      expect(
        userQueries.some((q) => q.get("filter[status]") === "invited"),
      ).toBe(true),
    );
  });
});
