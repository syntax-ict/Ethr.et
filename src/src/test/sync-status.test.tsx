import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { SyncStatus } from "@/features/attendance/components/mobile/sync-status";

beforeEach(() => {
  localStorage.setItem("locale", "en");
});
afterEach(() => vi.useRealTimers());

function view(over: Partial<Parameters<typeof SyncStatus>[0]> = {}) {
  return (
    <SyncStatus
      isOnline
      pendingCount={0}
      syncing={false}
      onSync={() => {}}
      {...over}
    />
  );
}

describe("SyncStatus", () => {
  it("offers to send what is waiting", () => {
    render(view({ pendingCount: 2 }));
    expect(screen.getByText(/2/)).toBeInTheDocument();
    expect(screen.getByRole("button")).toBeInTheDocument();
  });

  it("says what is happening while it sends, with no button", () => {
    const { rerender } = render(view({ pendingCount: 1, syncing: true }));
    expect(screen.getByText("Sending 1 punch…")).toBeInTheDocument();
    expect(screen.queryByRole("button")).toBeNull();
    rerender(view({ pendingCount: 3, syncing: true }));
    expect(screen.getByText("Sending 3 punches…")).toBeInTheDocument();
  });

  it("says nothing offline", () => {
    const { container } = render(view({ isOnline: false, pendingCount: 2 }));
    expect(container).toBeEmptyDOMElement();
  });

  it("says nothing when there was never anything to send", () => {
    const { container } = render(view());
    expect(container).toBeEmptyDOMElement();
  });

  it("tells that all punches went, then goes away after 4 seconds", () => {
    vi.useFakeTimers();
    const { rerender, container } = render(view({ pendingCount: 2 }));
    rerender(view({ pendingCount: 0 }));
    expect(screen.getByText("All punches sent.")).toBeInTheDocument();
    expect(screen.getByRole("status")).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(3900);
    });
    expect(screen.getByText("All punches sent.")).toBeInTheDocument();
    act(() => {
      vi.advanceTimersByTime(200);
    });
    expect(screen.queryByText("All punches sent.")).toBeNull();
    expect(container).toBeEmptyDOMElement();
  });
});
