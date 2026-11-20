import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiClient } from "@/api/client";
import { refreshOfflineGrant } from "@/lib/attendance/offline-grant";

/**
 * The grant lets the server vouch for a punch made with no signal. It is valid
 * for 72 hours, and is renewed once it is 6 hours old, so a change on the
 * server (a phone registered or replaced) reaches the phone within the day.
 */
const EMP = "E1";
const HOUR = 60 * 60 * 1000;

function store(issuedHoursAgo: number, extra: Record<string, unknown> = {}) {
  const issued = Date.now() - issuedHoursAgo * HOUR;
  localStorage.setItem(
    `ethr.offline_grant.${EMP}`,
    JSON.stringify({
      grant: "g",
      issued_at: new Date(issued).toISOString(),
      expires_at: new Date(issued + 72 * HOUR).toISOString(),
      ...extra,
    }),
  );
}

describe("refreshOfflineGrant", () => {
  let get: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    localStorage.clear();
    get = vi.spyOn(apiClient, "get").mockResolvedValue({
      data: { grant: "new", issued_at: "x", expires_at: "y" },
    } as never);
  });
  afterEach(() => vi.restoreAllMocks());

  it("fetches one when there is none", async () => {
    await refreshOfflineGrant(EMP);
    expect(get).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem(`ethr.offline_grant.${EMP}`)).toContain("new");
  });

  it("keeps a grant issued under 6 hours ago", async () => {
    store(5);
    await refreshOfflineGrant(EMP);
    expect(get).not.toHaveBeenCalled();
  });

  it("renews a grant issued more than 6 hours ago, with 66 hours still left", async () => {
    store(7);
    await refreshOfflineGrant(EMP);
    expect(get).toHaveBeenCalledTimes(1);
  });

  it("renews one whose issue time cannot be read", async () => {
    store(1, { issued_at: "not a date" });
    await refreshOfflineGrant(EMP);
    expect(get).toHaveBeenCalledTimes(1);
  });

  it("still renews at once for a newly registered phone the grant does not name", async () => {
    store(1);
    await refreshOfflineGrant(EMP, true);
    expect(get).toHaveBeenCalledTimes(1);
  });

  it("keeps the old grant when the renewal cannot reach the server", async () => {
    store(7);
    get.mockRejectedValue(new Error("offline"));
    await refreshOfflineGrant(EMP);
    expect(localStorage.getItem(`ethr.offline_grant.${EMP}`)).toContain('"g"');
  });
});
