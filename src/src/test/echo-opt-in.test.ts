import { afterEach, describe, expect, it, vi } from "vitest";

/**
 * Production has no Reverb server. `.env.production` used to point the client
 * at wss://ethr.et:443 and `initEcho()` defaulted to localhost:8080 with a
 * made-up key, so every logged-in page load opened a WebSocket that could
 * never connect. Realtime is now opt-in on NEXT_PUBLIC_REVERB_APP_KEY.
 */
describe("initEcho", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.resetModules();
    vi.doUnmock("laravel-echo");
  });

  it("opens no connection when no app key is configured", async () => {
    vi.stubEnv("NEXT_PUBLIC_REVERB_APP_KEY", "");
    const ctor = vi.fn();
    vi.doMock("laravel-echo", () => ({ default: ctor }));

    const { initEcho } = await import("@/lib/echo");

    await expect(initEcho()).resolves.toBeNull();
    expect(ctor).not.toHaveBeenCalled();
  });

  it("connects to the configured host when an app key is set", async () => {
    vi.stubEnv("NEXT_PUBLIC_REVERB_APP_KEY", "k");
    vi.stubEnv("NEXT_PUBLIC_REVERB_HOST", "ws.example.test");
    const ctor = vi.fn();
    vi.doMock("laravel-echo", () => ({ default: ctor }));
    vi.doMock("pusher-js", () => ({ default: class {} }));

    const { initEcho } = await import("@/lib/echo");
    await initEcho();

    expect(ctor).toHaveBeenCalledWith(
      expect.objectContaining({ key: "k", wsHost: "ws.example.test" }),
    );
  });

  it("ships no realtime host in the production env file", async () => {
    const { readFileSync } = await import("node:fs");
    const env = readFileSync(".env.production", "utf8");
    const active = env
      .split(/\r?\n/)
      .filter((l) => l.trim() && !l.trim().startsWith("#"));

    expect(active.filter((l) => l.startsWith("NEXT_PUBLIC_REVERB"))).toEqual(
      [],
    );
  });
});
