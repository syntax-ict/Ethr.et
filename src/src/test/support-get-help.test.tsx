import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { GetHelpDialogHost, openGetHelp } from "@/features/support/components";
import type { SupportRequest } from "@/features/support/api";
import {
  clearFailedRequests,
  recordFailedRequest,
} from "@/lib/support/recent-failures";
import SupportPage from "@/app/(dashboard)/support/page";
import ConsoleSupportPage from "@/app/(dashboard)/admin/support/page";

const permissions = vi.hoisted(() => ({ manageSupport: false }));
const search = vi.hoisted(() => ({ value: "" }));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: { manageSupport: permissions.manageSupport },
    isSuperAdmin: false,
  }),
}));

vi.mock("next/navigation", () => ({
  usePathname: () => "/support",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(search.value),
}));

vi.mock("@/lib/offline-queue", () => ({
  getPendingCount: async () => 3,
}));

/**
 * Get help: what the dialog sends, what it shows before sending, and the two
 * inboxes that read it back. The person sees every detail their screen
 * sends, and can leave the details out.
 */
function request(overrides: Partial<SupportRequest> = {}): SupportRequest {
  return {
    public_id: "01JSUPPORT0000000000000001",
    viewer: "requester",
    category: "attendance",
    body: "My punch this morning did not count.",
    status: "open",
    handled_by: "organisation",
    requester: { name: "Abebe Kebede", email: null, role: null },
    escalated_at: null,
    escalated_by_name: null,
    escalation_note: null,
    context: null,
    organisation: null,
    created_at: "2026-10-29T06:00:00Z",
    updated_at: "2026-10-29T06:00:00Z",
    resolved_at: null,
    messages: [],
    ...overrides,
  };
}

function wrap(node: React.ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{node}</QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
  permissions.manageSupport = false;
  search.value = "";
  clearFailedRequests();
});

describe("the Get help dialog", () => {
  it("sends the question with the page and recent failures, without query strings", async () => {
    let sent: Record<string, unknown> | null = null;
    server.use(
      http.post("*/api/v1/support-requests", async ({ request: req }) => {
        sent = (await req.json()) as Record<string, unknown>;
        return HttpResponse.json(request(), { status: 201 });
      }),
    );
    recordFailedRequest({
      method: "post",
      url: "/attendance/check-in?search=abebe@example.com",
      status: 422,
      type: "https://ethr.et/errors/validation",
    });
    // Asking about a failed help request does not report the asking.
    recordFailedRequest({
      method: "post",
      url: "/support-requests",
      status: 500,
    });

    const user = userEvent.setup();
    wrap(<GetHelpDialogHost />);
    openGetHelp();

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByText("Who should answer")).toBeNull();

    await user.click(within(dialog).getByRole("combobox"));
    await user.click(
      await screen.findByRole("option", { name: "Attendance and punching" }),
    );
    await user.type(
      within(dialog).getByLabelText("What happened"),
      "My punch did not count",
    );

    await user.click(
      within(dialog).getByRole("button", { name: "See what is included" }),
    );
    expect(
      within(dialog).getByText(/422 POST \/attendance\/check-in/),
    ).toBeInTheDocument();
    expect(
      within(dialog).getByText(/Punches waiting to sync: 3/),
    ).toBeInTheDocument();

    await user.click(within(dialog).getByRole("button", { name: "Send" }));

    await screen.findByText("Request sent");
    expect(sent).toMatchObject({
      category: "attendance",
      body: "My punch did not count",
      include_context: true,
      context: {
        pending_offline: 3,
        recent_errors: [
          {
            method: "POST",
            path: "/attendance/check-in",
            status: 422,
            type: "https://ethr.et/errors/validation",
          },
        ],
      },
    });
    expect(sent).not.toHaveProperty("send_to");
    expect(screen.getByRole("link", { name: "View request" })).toHaveAttribute(
      "href",
      "/support?request=01JSUPPORT0000000000000001",
    );
  });

  it("sends no screen details when they are switched off", async () => {
    let sent: Record<string, unknown> | null = null;
    server.use(
      http.post("*/api/v1/support-requests", async ({ request: req }) => {
        sent = (await req.json()) as Record<string, unknown>;
        return HttpResponse.json(request(), { status: 201 });
      }),
    );

    const user = userEvent.setup();
    wrap(<GetHelpDialogHost />);
    openGetHelp();
    const dialog = await screen.findByRole("dialog");

    await user.click(within(dialog).getByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Signing in" }));
    await user.type(
      within(dialog).getByLabelText("What happened"),
      "Cannot sign in",
    );
    await user.click(within(dialog).getByRole("switch"));
    await user.click(within(dialog).getByRole("button", { name: "Send" }));

    await screen.findByText("Request sent");
    expect(sent).toMatchObject({ include_context: false });
    expect(sent).not.toHaveProperty("context");
  });

  it("lets support people choose ETHR support", async () => {
    permissions.manageSupport = true;
    let sent: Record<string, unknown> | null = null;
    server.use(
      http.post("*/api/v1/support-requests", async ({ request: req }) => {
        sent = (await req.json()) as Record<string, unknown>;
        return HttpResponse.json(request({ handled_by: "ethr" }), {
          status: 201,
        });
      }),
    );

    const user = userEvent.setup();
    wrap(<GetHelpDialogHost />);
    openGetHelp();
    const dialog = await screen.findByRole("dialog");

    await user.click(
      within(dialog).getByRole("radio", { name: "ETHR support" }),
    );
    await user.click(within(dialog).getByRole("combobox"));
    await user.click(
      await screen.findByRole("option", { name: "Billing and plan" }),
    );
    await user.type(
      within(dialog).getByLabelText("What happened"),
      "Invoice is wrong",
    );
    await user.click(within(dialog).getByRole("button", { name: "Send" }));

    await screen.findByText("Request sent");
    expect(sent).toMatchObject({ send_to: "ethr", category: "billing" });
  });

  it("will not send without a topic and a message", async () => {
    wrap(<GetHelpDialogHost />);
    openGetHelp();
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByRole("button", { name: "Send" })).toBeDisabled();
  });
});

describe("the help requests page", () => {
  it("invites a first question when there is none", async () => {
    server.use(
      http.get("*/api/v1/support-requests/mine", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1 },
        }),
      ),
    );

    wrap(<SupportPage />);

    expect(
      await screen.findByText("You have not asked for help yet"),
    ).toBeInTheDocument();
  });

  it("opens a request from a notification link and reads ETHR's answer as ETHR support", async () => {
    search.value = "request=01JSUPPORT0000000000000001";
    server.use(
      http.get("*/api/v1/support-requests/mine", () =>
        HttpResponse.json({
          data: [request({ status: "answered", handled_by: "ethr" })],
          meta: { current_page: 1, last_page: 1 },
        }),
      ),
      http.get("*/api/v1/support-requests/01JSUPPORT0000000000000001", () =>
        HttpResponse.json(
          request({
            status: "answered",
            handled_by: "ethr",
            messages: [
              {
                public_id: "01JMSG",
                author: "ethr",
                author_name: null,
                body: "Fixed: the punch is on your record.",
                created_at: "2026-10-29T07:00:00Z",
              },
            ],
          }),
        ),
      ),
    );

    wrap(<SupportPage />);

    const dialog = await screen.findByRole("dialog");
    expect(
      await within(dialog).findByText("Fixed: the punch is on your record."),
    ).toBeInTheDocument();
    expect(within(dialog).getByText("ETHR support")).toBeInTheDocument();
    // The person who asked never gets the pass-on control.
    expect(
      within(dialog).queryByRole("button", { name: "Pass to ETHR support" }),
    ).toBeNull();
  });

  it("lets support people pass a colleague's request to ETHR with a note", async () => {
    permissions.manageSupport = true;
    search.value = "request=01JSUPPORT0000000000000001";
    let note: unknown = undefined;
    server.use(
      http.get("*/api/v1/support-requests/mine", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1 },
        }),
      ),
      http.get("*/api/v1/support-requests/01JSUPPORT0000000000000001", () =>
        HttpResponse.json(
          request({
            viewer: "organisation",
            requester: {
              name: "Abebe Kebede",
              email: "abebe@example.com",
              role: "employee",
            },
            context: {
              role: "employee",
              included: true,
              page: "/attendance",
              recent_errors: [],
            },
          }),
        ),
      ),
      http.post(
        "*/api/v1/support-requests/01JSUPPORT0000000000000001/escalate",
        async ({ request: req }) => {
          note = ((await req.json()) as { note: unknown }).note;
          return HttpResponse.json(
            request({ viewer: "organisation", handled_by: "ethr" }),
          );
        },
      ),
    );

    const user = userEvent.setup();
    wrap(<SupportPage />);

    const dialog = await screen.findByRole("dialog");
    expect(
      await within(dialog).findByText("Asked by Abebe Kebede"),
    ).toBeInTheDocument();
    expect(within(dialog).getByText("Technical details")).toBeInTheDocument();

    await user.click(
      within(dialog).getByRole("button", { name: "Pass to ETHR support" }),
    );
    await user.type(
      within(dialog).getByRole("textbox", { name: /Note for ETHR support/ }),
      "Device shows the punch",
    );
    await user.click(
      within(dialog)
        .getAllByRole("button", { name: "Pass to ETHR support" })
        .at(-1)!,
    );

    await waitFor(() => expect(note).toBe("Device shows the punch"));
  });
});

describe("the console inbox", () => {
  it("names the organisation and shows what the screen knew", async () => {
    search.value = "request=01JSUPPORT0000000000000001";
    const passed = request({
      viewer: "ethr",
      handled_by: "ethr",
      organisation: {
        public_id: "01JORG",
        name: "Acme Coffee",
        subdomain: "acme",
      },
      requester: {
        name: "Abebe Kebede",
        email: "abebe@example.com",
        role: "employee",
      },
      escalated_by_name: "Hana HR",
      escalation_note: "Device shows the punch",
      context: {
        role: "employee",
        included: true,
        page: "/attendance",
        pending_offline: 2,
        recent_errors: [
          {
            method: "POST",
            path: "/attendance/check-in",
            status: 422,
            type: null,
            at: null,
          },
        ],
      },
    });
    server.use(
      http.get("*/api/v1/admin/support-requests", () =>
        HttpResponse.json({
          data: [passed],
          meta: { current_page: 1, last_page: 1 },
          counts: { open: 1 },
        }),
      ),
      http.get(
        "*/api/v1/admin/support-requests/01JSUPPORT0000000000000001",
        () => HttpResponse.json(passed),
      ),
    );

    wrap(<ConsoleSupportPage />);

    const dialog = await screen.findByRole("dialog");
    expect(
      await within(dialog).findByRole("link", { name: "Acme Coffee" }),
    ).toHaveAttribute("href", "/admin/tenants/01JORG");
    expect(within(dialog).getByText("abebe@example.com")).toBeInTheDocument();
    expect(
      within(dialog).getByText("Device shows the punch"),
    ).toBeInTheDocument();
    expect(
      within(dialog).getByText(/422 POST \/attendance\/check-in/),
    ).toBeInTheDocument();
  });
});
