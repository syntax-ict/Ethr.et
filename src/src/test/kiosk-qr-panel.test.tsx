import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { KioskQrPanel } from "@/features/kiosk/components/kiosk-qr-panel";

/**
 * The kiosk shows a code a phone scans to punch in. The server issues it for
 * seconds and accepts one scan; the panel's job is to keep a live one on
 * screen and never a stale one.
 */
// The code itself; the card around it has icons that are svgs too.
const QR = '[role="img"] svg:not(.animate-spin)';

let issued: string[] = [];

function answer(ttl = 45) {
  server.use(
    http.post("*/kiosk/qr", ({ request }) => {
      issued.push(request.headers.get("X-Kiosk-Token") ?? "");
      return HttpResponse.json({
        token: `code-${issued.length}`,
        branch_public_id: "b1",
        branch_name: "Head Office",
        expires_at: "2026-11-06T09:00:45Z",
        generated_at: "2026-11-06T09:00:00Z",
        ttl_seconds: ttl,
      });
    }),
  );
}

async function settle() {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0);
  });
}

beforeEach(() => {
  issued = [];
  localStorage.setItem("locale", "en");
  vi.useFakeTimers({ shouldAdvanceTime: false });
});

afterEach(() => {
  vi.useRealTimers();
});

describe("KioskQrPanel", () => {
  it("asks with the kiosk token and shows the code", async () => {
    answer();
    const { container } = render(<KioskQrPanel token="kt" paused={false} />);
    await settle();

    expect(issued).toEqual(["kt"]);
    expect(container.querySelector(QR)).not.toBeNull();
    expect(screen.getByText("Scan to check in")).toBeInTheDocument();
  });

  it("asks for the next code before the shown one runs out", async () => {
    answer(45);
    render(<KioskQrPanel token="kt" paused={false} />);
    await settle();
    expect(issued).toHaveLength(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(34_000);
    });
    expect(issued).toHaveLength(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(2_000);
    });
    expect(issued).toHaveLength(2);
  });

  it("takes the code off the screen if it cannot get a new one in time", async () => {
    answer(20);
    const { container } = render(<KioskQrPanel token="kt" paused={false} />);
    await settle();
    expect(container.querySelector(QR)).not.toBeNull();

    server.use(http.post("*/kiosk/qr", () => HttpResponse.error()));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(21_000);
    });

    expect(container.querySelector(QR)).toBeNull();
    expect(screen.getByText(/Use your employee code/)).toBeInTheDocument();
  });

  it("shows nothing where QR is switched off", async () => {
    server.use(
      http.post("*/kiosk/qr", () => new HttpResponse(null, { status: 403 })),
    );
    const { container } = render(<KioskQrPanel token="kt" paused={false} />);
    await settle();

    expect(container).toBeEmptyDOMElement();
  });

  it("asks for nothing while the terminal is resting", async () => {
    answer();
    const { container, rerender } = render(
      <KioskQrPanel token="kt" paused={true} />,
    );
    await settle();
    expect(issued).toHaveLength(0);
    expect(container.querySelector(QR)).toBeNull();

    rerender(<KioskQrPanel token="kt" paused={false} />);
    await settle();
    expect(issued).toHaveLength(1);
  });
});
