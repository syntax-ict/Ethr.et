import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeviceEnrollmentsDialog } from "@/features/devices/components/device-workforce-dialogs";

const toastSuccess = vi.hoisted(() => vi.fn());
vi.mock("sonner", () => ({
  toast: { success: toastSuccess, error: vi.fn(), info: vi.fn() },
}));

/**
 * The enrollments dialog for a ZKTeco ADMS device (2026-10-28). ETHR cannot
 * read such a device, so the list is what it last reported: the dialog says
 * when that was, and lets an administrator ask again.
 */
function renderDialog() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(
    <DeviceEnrollmentsDialog
      devicePublicId="DEV1"
      deviceName="Main gate"
      onClose={() => {}}
    />,
    { wrapper: Wrapper },
  );
}

function enrollments(roster: Record<string, unknown>, people = 1) {
  return HttpResponse.json({
    device: { public_id: "DEV1", name: "Main gate", adapter_type: "zkteco_adms" },
    enrollments: Array.from({ length: people }, (_, i) => ({
      device_user_id: String(1042 + i),
      name: "Abebe Kebede",
      card_number: null,
      department: null,
      fingerprint_count: 2,
      face_registered: null,
      match: { outcome: "new", employee_public_id: null, confidence: 0 },
    })),
    summary: { total: people, matched: 0, probable: 0, ambiguous: 0, new: people },
    roster: {
      source: "reported",
      received_at: null,
      requested_at: null,
      can_request: true,
      ...roster,
    },
  });
}

describe("<DeviceEnrollmentsDialog> for a device that reports its list", () => {
  it("says the list has not arrived and asks the device when told to", async () => {
    let asked = 0;
    server.use(
      http.get("*/api/v1/devices/DEV1/enrollments", () =>
        enrollments(
          asked ? { requested_at: "2026-10-28T06:00:00Z" } : {},
          0,
        ),
      ),
      http.post("*/api/v1/devices/DEV1/enrollments/request", () => {
        asked++;
        return HttpResponse.json(
          {
            roster: {
              source: "reported",
              received_at: null,
              requested_at: "2026-10-28T06:00:00Z",
              can_request: true,
            },
          },
          { status: 202 },
        );
      }),
    );
    renderDialog();

    expect(
      await screen.findByText("The device has not sent its list yet"),
    ).toBeInTheDocument();
    await userEvent.click(
      screen.getByRole("button", { name: "Ask the device again" }),
    );

    expect(asked).toBe(1);
    await waitFor(() => expect(toastSuccess).toHaveBeenCalled());
    expect(
      await screen.findByText(
        "Waiting for the device. This list updates when it answers.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Ask the device again" }),
    ).toBeDisabled();
  });

  it("shows how many people the device reported, without a request button for a viewer", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1/enrollments", () =>
        enrollments(
          { received_at: new Date().toISOString(), can_request: false },
          2,
        ),
      ),
    );
    renderDialog();

    expect(
      await screen.findByText(/People on the device: 2, as last reported/),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Ask the device again" }),
    ).not.toBeInTheDocument();
  });

  it("shows no roster bar for a device read directly", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1/enrollments", () =>
        enrollments({ source: "live", can_request: false }),
      ),
    );
    renderDialog();

    expect(await screen.findByText("1042")).toBeInTheDocument();
    expect(
      screen.queryByText("The device has not sent its list yet"),
    ).not.toBeInTheDocument();
  });
});
