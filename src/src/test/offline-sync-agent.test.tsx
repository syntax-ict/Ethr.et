import { describe, expect, it, vi, beforeEach } from "vitest";
import { render as rtlRender } from "@testing-library/react";
import type { ReactElement } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { readFileSync } from "node:fs";
import { join } from "node:path";

const sync = vi.fn();
let me: { employee_public_id?: string | null } | undefined;

vi.mock("@/lib/hooks/useOfflineSync", () => ({
  QUEUE_CHANGED_EVENT: "ethr:offline-queue-changed",
  useOfflineSync: (id: string | null) => {
    sync(id);
    return {};
  },
}));
const flush = vi.hoisted(() => vi.fn());
vi.mock("@/lib/attendance/visit-sync", () => ({
  flushQueuedVisits: (id: string | null) => flush(id),
}));
vi.mock("@/features/auth/api", () => ({
  useCurrentUser: () => ({ data: me }),
}));

import { OfflineSyncAgent } from "@/components/shared/offline-sync-agent";

function render(ui: ReactElement) {
  return rtlRender(
    <QueryClientProvider client={new QueryClient()}>{ui}</QueryClientProvider>,
  );
}

describe("OfflineSyncAgent", () => {
  beforeEach(() => {
    sync.mockClear();
    flush.mockReset().mockResolvedValue({ sent: 0 });
  });

  it("sends the signed-in employee's punches and renders nothing", () => {
    me = { employee_public_id: "01HEMP" };
    const { container } = render(<OfflineSyncAgent />);

    expect(sync).toHaveBeenCalledWith("01HEMP");
    expect(container).toBeEmptyDOMElement();
  });

  it("sends nothing for an account with no employee record", () => {
    me = { employee_public_id: null };
    render(<OfflineSyncAgent />);

    expect(sync).toHaveBeenCalledWith(null);
  });

  it("sends the signed-in person's visits on mount, on reconnecting and when a punch run ends", () => {
    me = { employee_public_id: "01HEMP" };
    render(<OfflineSyncAgent />);
    expect(flush).toHaveBeenCalledTimes(1);
    expect(flush).toHaveBeenLastCalledWith("01HEMP");

    window.dispatchEvent(new Event("online"));
    expect(flush).toHaveBeenCalledTimes(2);

    window.dispatchEvent(new Event("ethr:offline-queue-changed"));
    expect(flush).toHaveBeenCalledTimes(3);
  });

  it("sends no visits when nobody with an employee record is signed in", () => {
    me = { employee_public_id: null };
    render(<OfflineSyncAgent />);
    window.dispatchEvent(new Event("online"));

    expect(flush).not.toHaveBeenCalled();
  });

  it("is mounted in the dashboard shell beside the banner", () => {
    const shell = readFileSync(
      join(__dirname, "../app/(dashboard)/dashboard-shell.tsx"),
      "utf8",
    );

    expect(shell).toMatch(/<OfflineBanner \/>\s*<OfflineSyncAgent \/>/);
  });
});
