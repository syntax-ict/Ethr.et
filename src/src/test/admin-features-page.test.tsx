import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import FeatureControlsPage from "@/app/(dashboard)/admin/features/page";
import type { PlatformFeature } from "@/features/admin/features";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ isSuperAdmin: true }),
}));
vi.mock("@/components/shared/role-gate", () => ({
  RoleGate: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

/**
 * The console's feature switches: each feature's platform state, a change
 * that cannot be sent without a reason, and Hidden left out until the screen
 * honours it.
 */
function feature(overrides: Partial<PlatformFeature> = {}): PlatformFeature {
  return {
    key: "payroll",
    kind: "module",
    overridable: true,
    state: "enabled",
    default_state: "enabled",
    reason: null,
    changed_at: null,
    grants: 0,
    revokes: 0,
    ...overrides,
  };
}

function serve(features: PlatformFeature[]) {
  server.use(
    http.get("*/api/v1/admin/features", () =>
      HttpResponse.json({ data: features }),
    ),
  );
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <FeatureControlsPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
});

describe("feature controls page", () => {
  it("lists each feature with its state and its organisation exceptions", async () => {
    serve([
      feature(),
      feature({
        key: "webhooks",
        state: "disabled",
        reason: "Delivery outage",
        changed_at: "2026-10-28T08:00:00Z",
        grants: 2,
        revokes: 1,
      }),
    ]);
    renderPage();

    const payroll = (await screen.findByText("Payroll")).closest("tr")!;
    expect(within(payroll).getByText("Enabled")).toBeInTheDocument();
    expect(within(payroll).getByText("Never changed")).toBeInTheDocument();

    const webhooks = screen.getByText("Webhooks").closest("tr")!;
    expect(within(webhooks).getByText("Disabled")).toBeInTheDocument();
    expect(
      within(webhooks).getByText("2 granted, 1 revoked"),
    ).toBeInTheDocument();
    expect(within(webhooks).getByText(/Delivery outage/)).toBeInTheDocument();
  });

  it("sends the new state with its reason, and only once a reason is given", async () => {
    serve([feature()]);
    let sent: unknown = null;
    server.use(
      http.put("*/api/v1/admin/features/payroll", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json(
          feature({
            state: "disabled",
            reason: "Bank file outage",
            changed_at: "2026-10-28T09:00:00Z",
          }),
        );
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: "Change Payroll" }),
    );
    const dialog = await screen.findByRole("dialog");

    await user.click(within(dialog).getByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Disabled" }));

    expect(
      within(dialog).getByText(/Off for every organisation, grants included/),
    ).toBeInTheDocument();

    const confirm = within(dialog).getByRole("button", {
      name: "Change state",
    });
    expect(confirm).toBeDisabled();

    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Bank file outage",
    );
    await user.click(confirm);

    await waitFor(() =>
      expect(sent).toEqual({ state: "disabled", reason: "Bank file outage" }),
    );
    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    );
    expect(
      within(screen.getByText("Payroll").closest("tr")!).getByText("Disabled"),
    ).toBeInTheDocument();
  });

  it("does not offer Hidden while nothing on screen honours it", async () => {
    serve([feature()]);
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: "Change Payroll" }),
    );
    await user.click(
      within(await screen.findByRole("dialog")).getByRole("combobox"),
    );

    const options = (await screen.findAllByRole("option")).map(
      (o) => o.textContent,
    );
    expect(options).toEqual(["Enabled", "Restricted", "Disabled"]);
  });

  it("groups features by kind and points to the SMS page", async () => {
    serve([
      feature(),
      feature({ key: "scim", kind: "integration" }),
      feature({ key: "public_news", kind: "public" }),
    ]);
    renderPage();

    expect(
      within(await screen.findByRole("table", { name: "Modules" })).getByText(
        "Payroll",
      ),
    ).toBeInTheDocument();
    expect(
      within(screen.getByRole("table", { name: "Integrations" })).getByText(
        "SCIM provisioning",
      ),
    ).toBeInTheDocument();
    expect(
      within(screen.getByRole("table", { name: "Public pages" })).getByText(
        "Public news page",
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Open SMS" })).toHaveAttribute(
      "href",
      "/admin/sms",
    );
  });

  it("does not offer a rollout for sign-up, which no organisation can be granted", async () => {
    serve([
      feature({
        key: "self_registration",
        kind: "integration",
        overridable: false,
      }),
    ]);
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", {
        name: "Change New organisation sign-up",
      }),
    );
    await user.click(
      within(await screen.findByRole("dialog")).getByRole("combobox"),
    );

    const options = (await screen.findAllByRole("option")).map(
      (o) => o.textContent,
    );
    expect(options).toEqual(["Enabled", "Disabled"]);
  });

  it("shows the server's field error under the reason", async () => {
    serve([feature()]);
    server.use(
      http.put("*/api/v1/admin/features/payroll", () =>
        HttpResponse.json(
          {
            errors: {
              reason: ["The reason field must be at least 3 characters."],
            },
          },
          { status: 422 },
        ),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: "Change Payroll" }),
    );
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Reason"), "abc");
    await user.click(
      within(dialog).getByRole("button", { name: "Change state" }),
    );

    expect(
      await within(dialog).findByText(
        "The reason field must be at least 3 characters.",
      ),
    ).toBeInTheDocument();
  });
});
