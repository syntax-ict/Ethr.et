import { beforeEach, describe, expect, it, vi } from "vitest";

const post = vi.hoisted(() => vi.fn());
const tenant = vi.hoisted(() => ({ value: "acme" as string | null }));
vi.mock("@/api/client", () => ({
  apiClient: { post },
  tenantHeaderValue: () => tenant.value,
}));

import {
  VISIT_QUEUE_CHANGED_EVENT,
  isSendableBy,
  loggedAt,
  queueVisit,
  queuedVisits,
  refusedVisitsOwnedBy,
  visitsOwnedBy,
} from "@/lib/attendance/visit-queue";
import {
  flushQueuedVisits,
  resetVisitSyncForTests,
} from "@/lib/attendance/visit-sync";
import {
  getSyncActivity,
  resetSyncActivityForTests,
} from "@/lib/offline-sync-activity";

const ME = "01HME";
const OTHER = "01HOTHER";

function visit(key: string, photo: string | null = null) {
  return {
    idempotency_key: key,
    latitude: 9,
    longitude: 38,
    note: null,
    photo,
  } as never;
}

function refusal(status: number) {
  return Object.assign(new Error("refused"), { response: { status } });
}

function problem(status: number, data: unknown) {
  return Object.assign(new Error("refused"), { response: { status, data } });
}

/** Visits still waiting to be sent; a refused one is kept but is not one of these. */
function keys() {
  return queuedVisits()
    .filter((v) => !v.refused)
    .map((v) => v.idempotency_key);
}

function refusedKeys() {
  return refusedVisitsOwnedBy(ME).map((v) => v.idempotency_key);
}

beforeEach(() => {
  localStorage.clear();
  post.mockReset().mockResolvedValue({ data: {} });
  tenant.value = "acme";
  resetVisitSyncForTests();
  resetSyncActivityForTests();
});

describe("loggedAt", () => {
  it("reads the moment a visit was logged from its key", () => {
    expect(loggedAt("visit-1795400000000-k3x9")).toBe(
      new Date(1795400000000).toISOString(),
    );
  });

  it("is null for a key that carries no time", () => {
    expect(loggedAt("bad")).toBeNull();
    expect(loggedAt("visit-abc-1")).toBeNull();
  });
});

describe("queueVisit", () => {
  it("stores the owner and organisation, and tells the screen", () => {
    const heard = vi.fn();
    window.addEventListener(VISIT_QUEUE_CHANGED_EVENT, heard);
    expect(queueVisit(visit("a"), ME, "acme")).toBe(true);
    window.removeEventListener(VISIT_QUEUE_CHANGED_EVENT, heard);

    expect(queuedVisits()[0]).toMatchObject({ owner: ME, tenant: "acme" });
    expect(heard).toHaveBeenCalled();
  });
});

describe("flushQueuedVisits", () => {
  it("posts exactly the visit, without owner or tenant, and drops it", async () => {
    queueVisit(visit("a"), ME, "acme");

    const { sent } = await flushQueuedVisits(ME);

    expect(sent).toBe(1);
    const [url, body] = post.mock.calls[0]!;
    expect(url).toBe("/attendance/visits");
    expect(body).not.toHaveProperty("owner");
    expect(body).not.toHaveProperty("tenant");
    expect(body.idempotency_key).toBe("a");
    expect(keys()).toEqual([]);
  });

  it("sends only this person's visits in this organisation", async () => {
    queueVisit(visit("mine"), ME, "acme");
    queueVisit(visit("theirs"), OTHER, "acme");
    queueVisit(visit("elsewhere"), ME, "globex");

    await flushQueuedVisits(ME);

    expect(post).toHaveBeenCalledTimes(1);
    expect(keys()).toEqual(["theirs", "elsewhere"]);
  });

  it("sends an old entry with no owner as the signed-in person's", async () => {
    localStorage.setItem(
      "ethr-visit-queue",
      JSON.stringify([{ idempotency_key: "old", latitude: 1 }]),
    );

    await flushQueuedVisits(ME);

    expect(post).toHaveBeenCalledTimes(1);
    expect(keys()).toEqual([]);
  });

  it("sends nothing with nobody signed in", async () => {
    queueVisit(visit("a"), ME, "acme");
    await flushQueuedVisits(null);
    expect(post).not.toHaveBeenCalled();
  });

  it.each([422, 404])(
    "stops sending a visit the server refuses with %i, and keeps it to be shown",
    async (s) => {
      queueVisit(visit("bad"), ME, "acme");
      queueVisit(visit("good"), ME, "acme");
      post.mockRejectedValueOnce(refusal(s));

      const { sent } = await flushQueuedVisits(ME);

      expect(sent).toBe(1);
      expect(keys()).toEqual([]);
      // It used to be dropped here, and nobody learned it had not counted.
      expect(refusedKeys()).toEqual(["bad"]);
    },
  );

  it("keeps the server's reason beside a refused visit", async () => {
    queueVisit(visit("bad"), ME, "acme");
    post.mockRejectedValueOnce(
      problem(422, {
        title: "Validation Failed",
        detail: "This visit is older than seven days.",
        errors: { note: ["x"] },
      }),
    );

    await flushQueuedVisits(ME);

    expect(keys()).toEqual([]);
    expect(refusedVisitsOwnedBy(ME)[0].refused?.reason).toBe(
      "This visit is older than seven days.",
    );
  });

  it("never sends a refused visit again, and does not count it as waiting", async () => {
    queueVisit(visit("bad"), ME, "acme");
    post.mockRejectedValueOnce(refusal(422));
    await flushQueuedVisits(ME);
    post.mockClear();

    const { sent } = await flushQueuedVisits(ME);

    expect(post).not.toHaveBeenCalled();
    expect(sent).toBe(0);
    expect(visitsOwnedBy(ME)).toEqual([]);
    expect(isSendableBy(refusedVisitsOwnedBy(ME)[0], ME, "acme")).toBe(false);
  });

  it("drops the photo of a refused visit, which nobody will look at", async () => {
    queueVisit(visit("bad", "data:image/jpeg;base64,AAAA"), ME, "acme");
    post.mockRejectedValueOnce(refusal(422));

    await flushQueuedVisits(ME);

    expect(refusedVisitsOwnedBy(ME)[0].photo).toBeNull();
  });

  it.each([
    ["no answer", new Error("Network Error"), "unreachable"],
    ["429", refusal(429), "busy"],
    ["500", refusal(500), "server"],
    ["a session that ended", refusal(401), null],
  ])("says why visits are still waiting after %s", async (_n, err, why) => {
    queueVisit(visit("first"), ME, "acme");
    post.mockRejectedValueOnce(err);

    await flushQueuedVisits(ME);

    expect(getSyncActivity().visitsWaitingBecause).toBe(why);
  });

  it("says phone attendance is off when that is why a visit is kept", async () => {
    queueVisit(visit("first"), ME, "acme");
    post.mockRejectedValueOnce(problem(422, { title: "Method Disabled" }));

    await flushQueuedVisits(ME);

    expect(getSyncActivity().visitsWaitingBecause).toBe("off");
  });

  it("clears the reason once a run goes through", async () => {
    queueVisit(visit("first"), ME, "acme");
    post.mockRejectedValueOnce(refusal(500));
    await flushQueuedVisits(ME);
    expect(getSyncActivity().visitsWaitingBecause).toBe("server");

    await flushQueuedVisits(ME);

    expect(getSyncActivity().visitsWaitingBecause).toBeNull();
  });

  it("keeps a Method Disabled 422 and still tries the next visit", async () => {
    queueVisit(visit("first"), ME, "acme");
    queueVisit(visit("second"), ME, "acme");
    post.mockRejectedValueOnce(problem(422, { title: "Method Disabled" }));

    const { sent } = await flushQueuedVisits(ME);

    expect(post).toHaveBeenCalledTimes(2);
    expect(sent).toBe(1);
    expect(keys()).toEqual(["first"]);
  });

  it("keeps a 403 visit and carries on to the next", async () => {
    queueVisit(visit("first"), ME, "acme");
    queueVisit(visit("second"), ME, "acme");
    post.mockRejectedValueOnce(refusal(403));

    const { sent } = await flushQueuedVisits(ME);

    expect(post).toHaveBeenCalledTimes(2);
    expect(sent).toBe(1);
    expect(keys()).toEqual(["first"]);
  });

  it.each([
    ["no answer", new Error("Network Error")],
    ["401", refusal(401)],
    ["419", refusal(419)],
    ["429", refusal(429)],
    ["500", refusal(500)],
  ])("keeps the visit on %s and stops the run", async (_n, err) => {
    queueVisit(visit("first"), ME, "acme");
    queueVisit(visit("second"), ME, "acme");
    post.mockRejectedValueOnce(err);

    await flushQueuedVisits(ME);

    expect(post).toHaveBeenCalledTimes(1);
    expect(keys()).toEqual(["first", "second"]);
  });

  it("on 413 sends again without the photo and drops the visit", async () => {
    queueVisit(visit("big", "data:image/jpeg;base64,AAAA"), ME, "acme");
    post.mockRejectedValueOnce(refusal(413));

    const { sent } = await flushQueuedVisits(ME);

    expect(sent).toBe(1);
    expect(post).toHaveBeenCalledTimes(2);
    expect(post.mock.calls[1]![1].photo).toBeNull();
    expect(keys()).toEqual([]);
  });

  it("keeps the visit when 413 persists without the photo, and carries on", async () => {
    queueVisit(visit("big", "data:image/jpeg;base64,AAAA"), ME, "acme");
    queueVisit(visit("next"), ME, "acme");
    post
      .mockRejectedValueOnce(refusal(413))
      .mockRejectedValueOnce(refusal(413));

    const { sent } = await flushQueuedVisits(ME);

    expect(post).toHaveBeenCalledTimes(3);
    expect(sent).toBe(1);
    expect(keys()).toEqual(["big"]);
    expect(queuedVisits()[0]?.photo).toBe("data:image/jpeg;base64,AAAA");
  });
});

describe("isSendableBy", () => {
  const entry = (tenant: string | null) =>
    ({ idempotency_key: "a", owner: ME, tenant }) as never;

  it("sends across the two ways of reaching one organisation", () => {
    expect(isSendableBy(entry("qa-audit"), ME, null)).toBe(true);
    expect(isSendableBy(entry(null), ME, "qa-audit")).toBe(true);
  });

  it("refuses two different organisations", () => {
    expect(isSendableBy(entry("qa-audit"), ME, "globex")).toBe(false);
  });

  it("still refuses another owner", () => {
    expect(isSendableBy(entry("qa-audit"), OTHER, "qa-audit")).toBe(false);
  });
});

describe("visitsOwnedBy", () => {
  it("counts the person's own and unowned entries, not another's", () => {
    localStorage.setItem(
      "ethr-visit-queue",
      JSON.stringify([
        { idempotency_key: "a", owner: ME },
        { idempotency_key: "b", owner: OTHER },
        { idempotency_key: "c" },
      ]),
    );
    expect(visitsOwnedBy(ME).map((v) => v.idempotency_key)).toEqual(["a", "c"]);
  });
});
