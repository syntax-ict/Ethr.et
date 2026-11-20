import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  QuarantineCard,
  reasonKey,
} from "@/features/devices/components/quarantine-card";

const toastSuccess = vi.hoisted(() => vi.fn());
vi.mock("sonner", () => ({
  toast: { success: toastSuccess, error: vi.fn(), info: vi.fn() },
}));

/**
 * The "Punches that need attention" card on a device: what a terminal sent
 * that could not be recorded, each with the reason in words. Replay and
 * Dismiss are for those who may change the device.
 */
function renderCard(canUpdate = true) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<QuarantineCard deviceId="DEV1" canUpdate={canUpdate} />, {
    wrapper: Wrapper,
  });
}

const ROW = {
  public_id: "01HQ0000000000000000000001",
  badge: "1001",
  punch_type: "check_in",
  occurred_raw: "2026-10-14T08:00:00+03:00",
  failure: "refused:employee_has_left",
  error: "The employee has left.",
  failed_at: "2026-10-14T05:00:05Z",
  received_at: "2026-10-14T05:00:04Z",
  raw: null,
};

function serveList(data: object[]) {
  server.use(
    http.get("*/api/v1/devices/DEV1/quarantine", () =>
      HttpResponse.json({
        data,
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: data.length,
      }),
    ),
  );
}

describe("reasonKey", () => {
  it("names a known reason and falls back for a new one", () => {
    expect(reasonKey("clock_ahead")).toBe("clock_ahead");
    expect(reasonKey("refused:outside_geofence")).toBe("geofence");
    expect(reasonKey("refused:something_new")).toBe("other");
    expect(reasonKey(null)).toBe("other");
  });
});

describe("<QuarantineCard>", () => {
  it("lists a punch with the reason in plain words", async () => {
    serveList([ROW]);
    renderCard();

    expect(
      await screen.findByText(/This person has left\./),
    ).toBeInTheDocument();
    expect(screen.getByText(/Badge 1001/)).toBeInTheDocument();
  });

  it("says nothing needs attention when the list is empty", async () => {
    serveList([]);
    renderCard();

    expect(await screen.findByText(/Nothing needs attention/)).toBeVisible();
  });

  it("shows a retry when the list cannot be read", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1/quarantine", () =>
        HttpResponse.json({ message: "no" }, { status: 500 }),
      ),
    );
    renderCard();

    expect(
      await screen.findByRole("button", { name: /try again|retry/i }),
    ).toBeInTheDocument();
  });

  it("sends the selected punches again by public id", async () => {
    serveList([ROW]);
    let body: unknown = null;
    server.use(
      http.post("*/api/v1/devices/quarantine/replay", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ replayed: 1, skipped: 0 });
      }),
    );
    const user = userEvent.setup();
    renderCard();

    await user.click(await screen.findByRole("checkbox"));
    await user.click(
      screen.getByRole("button", { name: /send selected again/i }),
    );

    await vi.waitFor(() =>
      expect(body).toEqual({ ids: ["01HQ0000000000000000000001"] }),
    );
    await vi.waitFor(() => expect(toastSuccess).toHaveBeenCalled());
  });

  it("asks before it dismisses", async () => {
    serveList([ROW]);
    let dismissed = false;
    server.use(
      http.post("*/api/v1/devices/quarantine/dismiss", () => {
        dismissed = true;
        return HttpResponse.json({ dismissed: 1 });
      }),
    );
    const user = userEvent.setup();
    renderCard();

    await user.click(await screen.findByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: /dismiss selected/i }));

    const dialog = await screen.findByRole("dialog");
    expect(dismissed).toBe(false);
    await user.click(within(dialog).getByRole("button", { name: "Dismiss" }));

    await vi.waitFor(() => expect(dismissed).toBe(true));
  });

  it("shows no buttons or checkboxes to someone who may not change the device", async () => {
    serveList([ROW]);
    renderCard(false);

    await screen.findByText(/This person has left\./);
    expect(screen.queryByRole("checkbox")).toBeNull();
    expect(screen.queryByRole("button", { name: /send selected/i })).toBeNull();
  });
});
