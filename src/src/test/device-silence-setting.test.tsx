import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeviceSilenceSetting } from "@/features/devices/components/device-silence-setting";

/**
 * Whether the admins are told when a terminal that only sends goes silent, and
 * how soon. Saved with the organisation's settings, so shown only to someone
 * who can change them.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

let canManageSettings = true;
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: { manageSettings: canManageSettings },
    hasPermission: () => canManageSettings,
  }),
}));

function renderCard() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <DeviceSilenceSetting />
    </QueryClientProvider>,
  );
}

function serveSettings(devices: object) {
  server.use(
    http.get("*/api/v1/settings", () => HttpResponse.json({ devices })),
  );
}

beforeEach(() => {
  canManageSettings = true;
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("<DeviceSilenceSetting>", () => {
  it("shows what is saved, and says the scheduler can run late", async () => {
    serveSettings({
      silence_alerts: true,
      silence_adms_minutes: 45,
      silence_push_grace_minutes: 90,
    });
    renderCard();

    expect(await screen.findByLabelText(/Not connected after/)).toHaveValue(45);
    expect(screen.getByLabelText(/No punches after the day began/)).toHaveValue(
      90,
    );
    // The caveat lives behind "How it works"; open the disclosures to read it.
    for (const summary of screen.getAllByText("How it works")) {
      await userEvent.click(summary);
    }
    expect(screen.getByText(/10 to 20 minutes late/)).toBeVisible();
  });

  it("is not drawn for someone who cannot change the organisation's settings", () => {
    canManageSettings = false;
    serveSettings({
      silence_alerts: true,
      silence_adms_minutes: 30,
      silence_push_grace_minutes: 60,
    });
    renderCard();

    expect(screen.queryByText("Silent terminals")).not.toBeInTheDocument();
  });

  it("refuses minutes out of range, and saves the three values it was given", async () => {
    let sent: unknown = null;
    serveSettings({
      silence_alerts: true,
      silence_adms_minutes: 30,
      silence_push_grace_minutes: 60,
    });
    server.use(
      http.put("*/api/v1/settings", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ message: "Settings updated", settings: {} });
      }),
    );
    const user = userEvent.setup();
    renderCard();

    const adms = await screen.findByLabelText(/Not connected after/);
    await user.clear(adms);
    await user.type(adms, "5");
    await user.click(screen.getByRole("button", { name: "Save" }));
    expect(
      await screen.findByText("Enter whole minutes from 15 to 240"),
    ).toBeVisible();
    expect(sent).toBeNull();

    await user.clear(adms);
    await user.type(adms, "40");
    await user.click(screen.getByRole("switch"));
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() =>
      expect(sent).toEqual({
        settings: {
          device_silence_alerts: false,
          device_silence_adms_minutes: 40,
          device_silence_push_grace_minutes: 60,
        },
      }),
    );
  });
});
