import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import { OfflineBanner } from "@/components/shared/offline-banner";
import * as offlineQueue from "@/lib/offline-queue";
import {
  SENT_SHOWN_FOR_MS,
  getSyncActivity,
  resetSyncActivityForTests,
  syncFinished,
  syncStarted,
} from "@/lib/offline-sync-activity";

function setNavigatorOnline(value: boolean) {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value,
  });
}

describe("OfflineBanner", () => {
  beforeEach(() => {
    setNavigatorOnline(true);
    resetSyncActivityForTests();
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(0);
    vi.spyOn(offlineQueue, "getRefusedRecords").mockResolvedValue([]);
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
    expect(
      await screen.findByText("3 records waiting to be sent"),
    ).toBeInTheDocument();
  });

  it("says one record, not one records", async () => {
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(1);
    render(<OfflineBanner />);
    expect(
      await screen.findByText("1 record waiting to be sent"),
    ).toBeInTheDocument();
  });

  it("offline, says the work is saved on this phone", async () => {
    setNavigatorOnline(false);
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(2);
    render(<OfflineBanner />);
    expect(await screen.findByText(/you're offline/i)).toBeInTheDocument();
    expect(
      await screen.findByText(/2 records saved on this phone/),
    ).toBeInTheDocument();
  });
});

describe("OfflineBanner while the phone is sending", () => {
  beforeEach(() => {
    setNavigatorOnline(true);
    resetSyncActivityForTests();
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(0);
    vi.spyOn(offlineQueue, "getRefusedRecords").mockResolvedValue([]);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it("says it is sending, then that it was sent, then lets go", async () => {
    render(<OfflineBanner />);
    const region = screen.getByRole("status");
    await waitFor(() => expect(region).toBeEmptyDOMElement());

    act(() => syncStarted("punches", 2));
    expect(await screen.findByText("Sending 2 records…")).toBeInTheDocument();

    vi.useFakeTimers({ shouldAdvanceTime: true });
    act(() => syncFinished("punches", 2));
    expect(
      screen.getByText("2 records sent. They are on the server now."),
    ).toBeInTheDocument();
    expect(screen.getByTestId("offline-banner")).toHaveAttribute(
      "data-tone",
      "success",
    );

    act(() => {
      vi.advanceTimersByTime(SENT_SHOWN_FOR_MS + 50);
    });
    expect(getSyncActivity().lastSent).toBeNull();
    expect(screen.queryByText(/sent\. /)).not.toBeInTheDocument();
  });

  it("adds a visit sent straight after the punches to the same line", async () => {
    render(<OfflineBanner />);
    act(() => {
      syncStarted("punches", 1);
      syncFinished("punches", 1);
      syncStarted("visits", 1);
      syncFinished("visits", 1);
    });
    expect(
      await screen.findByText("2 records sent. They are on the server now."),
    ).toBeInTheDocument();
  });

  it("says nothing was sent when the run sent nothing", async () => {
    render(<OfflineBanner />);
    act(() => {
      syncStarted("punches", 1);
      syncFinished("punches", 0);
    });
    await waitFor(() =>
      expect(screen.getByRole("status")).toBeEmptyDOMElement(),
    );
  });

  it("points at the punch screen when the server refused a punch", async () => {
    vi.spyOn(offlineQueue, "getRefusedRecords").mockResolvedValue([
      { id: 1 } as offlineQueue.OfflineAttendanceRecord,
    ]);
    render(<OfflineBanner />);
    expect(
      await screen.findByText("1 punch could not be recorded."),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "See why" })).toHaveAttribute(
      "href",
      "/attendance/mobile",
    );
  });
});

describe("OfflineBanner on a shared phone", () => {
  beforeEach(() => {
    setNavigatorOnline(true);
    resetSyncActivityForTests();
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(0);
    vi.spyOn(offlineQueue, "getRefusedRecords").mockResolvedValue([]);
  });

  afterEach(() => {
    localStorage.removeItem("ethr.offline-identity");
    vi.restoreAllMocks();
  });

  it("counts the signed-in person's waiting punches, not everyone's", async () => {
    localStorage.setItem(
      "ethr.offline-identity",
      JSON.stringify({
        v: 1,
        user: { public_id: "U1", employee_public_id: "E9" },
        permissions: [],
        plan_features: null,
        tenant: null,
      }),
    );
    render(<OfflineBanner />);

    await waitFor(() =>
      expect(offlineQueue.getPendingCount).toHaveBeenCalledWith("E9"),
    );
  });

  it("counts every waiting punch when no identity is kept", async () => {
    render(<OfflineBanner />);

    await waitFor(() =>
      expect(offlineQueue.getPendingCount).toHaveBeenCalledWith(null),
    );
  });
});
