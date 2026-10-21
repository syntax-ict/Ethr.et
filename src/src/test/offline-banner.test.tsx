import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { OfflineBanner } from "@/components/shared/offline-banner";
import * as offlineQueue from "@/lib/offline-queue";

function setNavigatorOnline(value: boolean) {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value,
  });
}

describe("OfflineBanner", () => {
  beforeEach(() => {
    setNavigatorOnline(true);
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(0);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("shows nothing when online with no pending records", async () => {
    render(<OfflineBanner />);
    // The live region stays mounted, empty, so a later message is announced.
    const region = screen.getByRole("status");
    await waitFor(() => expect(region).toBeEmptyDOMElement());
    expect(region).toHaveAttribute("aria-live", "polite");
  });

  it("shows an offline message when the browser goes offline", async () => {
    setNavigatorOnline(false);
    render(<OfflineBanner />);
    expect(await screen.findByText(/you're offline/i)).toBeInTheDocument();
  });

  it("shows the pending record count even while online", async () => {
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(3);
    render(<OfflineBanner />);
    expect(await screen.findByText(/3/)).toBeInTheDocument();
    expect(screen.getByText(/waiting to sync/i)).toBeInTheDocument();
  });
});
