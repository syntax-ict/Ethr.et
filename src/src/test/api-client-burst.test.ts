import { beforeEach, describe, expect, it } from "vitest";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { apiClient, MAX_IN_FLIGHT } from "@/api/client";

/**
 * Production answers a plain HTML 503 when its five PHP workers are all busy,
 * and a dashboard load fires about ten reads at once. The client holds at most
 * MAX_IN_FLIGHT on the network and sends a read the host turned away again.
 */
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

let hits = 0;

beforeEach(() => {
  hits = 0;
  // A write needs the XSRF cookie, or the client fetches it first.
  document.cookie = "XSRF-TOKEN=present; path=/";
});

const busyPage = () =>
  new HttpResponse("<html>Service Unavailable</html>", {
    status: 503,
    headers: { "Content-Type": "text/html", "Retry-After": "0" },
  });

describe("apiClient concurrency cap", () => {
  it("never has more than the cap on the network, and answers all", async () => {
    let current = 0;
    let peak = 0;
    server.use(
      http.get("*/api/v1/burst", async () => {
        current += 1;
        peak = Math.max(peak, current);
        await sleep(30);
        current -= 1;
        return HttpResponse.json({ ok: true });
      }),
    );

    const results = await Promise.all(
      Array.from({ length: 10 }, () => apiClient.get("/burst")),
    );

    expect(results).toHaveLength(10);
    expect(results.every((r) => r.status === 200)).toBe(true);
    expect(peak).toBeLessThanOrEqual(MAX_IN_FLIGHT);
  });

  it("frees the slot of a request that fails", async () => {
    server.use(
      http.get("*/api/v1/burst-fail", () =>
        HttpResponse.json({ message: "boom" }, { status: 500 }),
      ),
      http.get("*/api/v1/burst-ok", () => HttpResponse.json({ ok: true })),
    );

    const failed = await Promise.allSettled(
      Array.from({ length: 3 }, () => apiClient.get("/burst-fail")),
    );
    const ok = await Promise.allSettled(
      Array.from({ length: 3 }, () => apiClient.get("/burst-ok")),
    );

    expect(failed.every((r) => r.status === "rejected")).toBe(true);
    expect(ok.every((r) => r.status === "fulfilled")).toBe(true);
  });
});

describe("apiClient and a host that is out of workers", () => {
  it("sends a turned-away read again and returns the answer", async () => {
    server.use(
      http.get("*/api/v1/busy", () => {
        hits += 1;
        return hits <= 2 ? busyPage() : HttpResponse.json({ ok: true });
      }),
    );

    const response = await apiClient.get("/busy");

    expect(response.status).toBe(200);
    expect(hits).toBe(3);
  });

  it("gives up after two more tries", async () => {
    server.use(
      http.get("*/api/v1/busy-always", () => {
        hits += 1;
        return busyPage();
      }),
    );

    await expect(apiClient.get("/busy-always")).rejects.toMatchObject({
      response: { status: 503 },
    });
    expect(hits).toBe(3);
  });

  it("never sends a write again", async () => {
    server.use(
      http.post("*/api/v1/busy-write", () => {
        hits += 1;
        return busyPage();
      }),
    );

    await expect(apiClient.post("/busy-write", {})).rejects.toMatchObject({
      response: { status: 503 },
    });
    expect(hits).toBe(1);
  });

  it("leaves the API's own 503 alone", async () => {
    server.use(
      http.get("*/api/v1/paused", () => {
        hits += 1;
        return HttpResponse.json(
          { type: "https://ethr.et/errors/paused", status: 503 },
          {
            status: 503,
            headers: { "Content-Type": "application/problem+json" },
          },
        );
      }),
    );

    await expect(apiClient.get("/paused")).rejects.toMatchObject({
      response: { status: 503 },
    });
    expect(hits).toBe(1);
  });
});
