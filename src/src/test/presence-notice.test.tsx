import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

const nav = vi.hoisted(() => ({ pathname: "/dashboard" }));
vi.mock("next/navigation", () => ({
  usePathname: () => nav.pathname,
  useRouter: () => ({ push: vi.fn() }),
}));
const toasts = vi.hoisted(() => ({ toast: vi.fn() }));
vi.mock("sonner", () => ({ toast: toasts.toast }));

import { PunchReminders } from "@/features/attendance/components/punch-reminders";

/**
 * A presence check pending while the person is somewhere other than the punch
 * screen: one notice per check, never on the punch screen itself.
 */
const asked = new Date(Date.now() - 60_000).toISOString();
const shift = (pending: unknown) => ({
  date: "2026-11-18",
  timezone: "Africa/Addis_Ababa",
  working_day: true,
  holiday: null,
  on_leave: false,
  day: { kind: "working", weekend: false, holiday: null },
  shift: null,
  attendance: {
    status: "checked_in",
    record_public_id: "R1",
    check_in: new Date(Date.now() - 3_600_000).toISOString(),
    check_out: null,
    status_label: null,
    auto_checked_out: false,
  },
  policy: { mobile_enabled: true },
  assistant: {
    reminders: false,
    reminders_allowed: false,
    reminder_lead_minutes: 10,
    auto_check_in: false,
    auto_check_in_allowed: false,
    auto_checkout: false,
    auto_checkout_after_minutes: 0,
  },
  presence: { enabled: true, pending },
});

function mount(pending: unknown) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "U1",
          name: "Almaz",
          role: "employee",
          employee_public_id: "E1",
        },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: [],
      }),
    ),
    http.get("*/api/v1/attendance/my-shift", () =>
      HttpResponse.json(shift(pending)),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PunchReminders />
    </QueryClientProvider>,
  );
}

const pending = {
  asked_at: asked,
  expires_at: new Date(Date.now() + 14 * 60_000).toISOString(),
};

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
  toasts.toast.mockClear();
  nav.pathname = "/dashboard";
});

describe("the presence notice", () => {
  it("tells a person elsewhere in the app, once per check", async () => {
    const first = mount(pending);
    await waitFor(() => expect(toasts.toast).toHaveBeenCalledOnce());
    expect(toasts.toast.mock.calls[0][0]).toBe("Confirm you are at work");

    first.unmount();
    mount(pending);
    await new Promise((r) => setTimeout(r, 300));
    expect(toasts.toast).toHaveBeenCalledOnce();
  });

  it("stays silent on the punch screen, where the card is", async () => {
    nav.pathname = "/attendance/mobile";
    mount(pending);
    await new Promise((r) => setTimeout(r, 300));
    expect(toasts.toast).not.toHaveBeenCalled();
  });

  it("says nothing when no check is pending", async () => {
    mount(null);
    await new Promise((r) => setTimeout(r, 300));
    expect(toasts.toast).not.toHaveBeenCalled();
  });
});
