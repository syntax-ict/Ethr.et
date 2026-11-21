import { describe, expect, it, vi, beforeEach } from "vitest";
import { render } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { join } from "node:path";

const sync = vi.fn();
let me: { employee_public_id?: string | null } | undefined;

vi.mock("@/lib/hooks/useOfflineSync", () => ({
  useOfflineSync: (id: string | null) => {
    sync(id);
    return {};
  },
}));
vi.mock("@/features/auth/api", () => ({
  useCurrentUser: () => ({ data: me }),
}));

import { OfflineSyncAgent } from "@/components/shared/offline-sync-agent";

describe("OfflineSyncAgent", () => {
  beforeEach(() => sync.mockClear());

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

  it("is mounted in the dashboard shell beside the banner", () => {
    const shell = readFileSync(
      join(__dirname, "../app/(dashboard)/dashboard-shell.tsx"),
      "utf8",
    );

    expect(shell).toMatch(/<OfflineBanner \/>\s*<OfflineSyncAgent \/>/);
  });
});
