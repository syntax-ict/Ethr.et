import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { TenantFeaturesCard } from "@/app/(dashboard)/admin/tenants/[id]/tenant-features-card";
import type { TenantFeature } from "@/features/admin/features";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ isSuperAdmin: true }),
}));

/**
 * One organisation's features in the console: what is on, why, and the
 * grant or revoke that explains an exception to its plan.
 */
const ID = "01HZTENANT001";

function row(overrides: Partial<TenantFeature> = {}): TenantFeature {
  return {
    key: "payroll",
    on: false,
    visible: false,
    reason: "plan",
    override: null,
    ...overrides,
  };
}

function serve(rows: TenantFeature[]) {
  server.use(
    http.get(`*/api/v1/admin/tenants/${ID}/features`, () =>
      HttpResponse.json({ data: rows }),
    ),
  );
}

function renderCard(readOnly = false) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <TenantFeaturesCard publicId={ID} name="Acme" readOnly={readOnly} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
});

describe("an organisation's features in the console", () => {
  it("says why each feature is on or off for it", async () => {
    serve([
      row(),
      row({ key: "attendance", on: true, visible: true, reason: "enabled" }),
      row({
        key: "webhooks",
        on: false,
        reason: "revoked",
        override: {
          mode: "revoke",
          reason: "Abuse",
          expires_at: null,
          expired: false,
          changed_at: "2026-10-28T08:00:00Z",
        },
      }),
    ]);
    renderCard();

    const payroll = (await screen.findByText("Payroll")).closest("tr")!;
    expect(
      within(payroll).getByText("Off: not in this organisation's plan"),
    ).toBeInTheDocument();

    const webhooks = screen.getByText("Webhooks").closest("tr")!;
    expect(
      within(webhooks).getByText("Off: revoked for this organisation"),
    ).toBeInTheDocument();
    expect(
      within(webhooks).getByText("Revoked, no end date"),
    ).toBeInTheDocument();
  });

  it("grants a feature with a 30 day end date by default, and a reason", async () => {
    serve([row()]);
    let sent: Record<string, unknown> | null = null;
    server.use(
      http.put(
        `*/api/v1/admin/tenants/${ID}/features/payroll`,
        async ({ request }) => {
          sent = (await request.json()) as Record<string, unknown>;
          return HttpResponse.json({
            data: [
              row({
                on: true,
                visible: true,
                reason: "granted",
                override: {
                  mode: "grant",
                  reason: "Pilot",
                  expires_at: "2026-11-27T20:59:59Z",
                  expired: false,
                  changed_at: "2026-10-28T09:00:00Z",
                },
              }),
            ],
          });
        },
      ),
    );
    const user = userEvent.setup();
    renderCard();

    await user.click(
      await screen.findByRole("button", {
        name: "Set an exception for Payroll",
      }),
    );
    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText("Acme can use Payroll whatever its plan says."),
    ).toBeInTheDocument();

    const until = within(dialog).getByLabelText("Ends on") as HTMLInputElement;
    const expected = new Date(Date.now() + 30 * 86_400_000)
      .toISOString()
      .slice(0, 10);
    expect(until.value).toBe(expected);

    await user.type(within(dialog).getByLabelText("Reason"), "Pilot");
    await user.click(
      within(dialog).getByRole("button", { name: "Save exception" }),
    );

    await waitFor(() =>
      expect(sent).toEqual({
        mode: "grant",
        reason: "Pilot",
        expires_at: `${expected}T23:59:59+03:00`,
      }),
    );
    expect(
      await screen.findByText("On: granted to this organisation"),
    ).toBeInTheDocument();
  });

  it("removes an exception with a reason", async () => {
    serve([
      row({
        on: true,
        reason: "granted",
        override: {
          mode: "grant",
          reason: "Pilot",
          expires_at: null,
          expired: false,
          changed_at: "2026-10-28T08:00:00Z",
        },
      }),
    ]);
    let sent: unknown = null;
    server.use(
      http.delete(
        `*/api/v1/admin/tenants/${ID}/features/payroll`,
        async ({ request }) => {
          sent = await request.json();
          return HttpResponse.json({ data: [row()] });
        },
      ),
    );
    const user = userEvent.setup();
    renderCard();

    await user.click(
      await screen.findByRole("button", {
        name: "Remove the exception for Payroll",
      }),
    );
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Reason"), "Pilot ended");
    await user.click(within(dialog).getByRole("button", { name: "Remove" }));

    await waitFor(() => expect(sent).toEqual({ reason: "Pilot ended" }));
    expect(
      await screen.findByText("Off: not in this organisation's plan"),
    ).toBeInTheDocument();
  });

  it("offers no changes without MFA or for a deleted organisation", async () => {
    serve([row()]);
    renderCard(true);

    await screen.findByText("Payroll");
    expect(
      screen.queryByRole("button", { name: "Set an exception for Payroll" }),
    ).not.toBeInTheDocument();
  });
});
