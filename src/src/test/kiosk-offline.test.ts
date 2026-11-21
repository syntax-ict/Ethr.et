import "fake-indexeddb/auto";
import { describe, it, expect, beforeEach } from "vitest";
import axios, { AxiosError } from "axios";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  dropPunch,
  isUnreachable,
  queuedPunches,
  savePunchOffline,
  sealPunch,
  syncQueuedPunches,
} from "@/features/kiosk/offline";

/**
 * A kiosk through a power cut: punches are sealed with the kiosk's public key
 * so only the server can open them, wait in IndexedDB, and leave the queue
 * once the server has answered for them - and only then.
 */
async function keyPair() {
  const pair = await crypto.subtle.generateKey(
    {
      name: "RSA-OAEP",
      modulusLength: 2048,
      publicExponent: new Uint8Array([1, 0, 1]),
      hash: "SHA-1",
    },
    true,
    ["encrypt", "decrypt"],
  );
  const spki = new Uint8Array(
    await crypto.subtle.exportKey("spki", pair.publicKey),
  );
  return {
    publicKey: btoa(String.fromCharCode(...spki)),
    privateKey: pair.privateKey,
  };
}

async function open(privateKey: CryptoKey, sealed: string) {
  const bytes = Uint8Array.from(atob(sealed), (c) => c.charCodeAt(0));
  const plain = await crypto.subtle.decrypt(
    { name: "RSA-OAEP" },
    privateKey,
    bytes,
  );
  return JSON.parse(new TextDecoder().decode(plain));
}

beforeEach(async () => {
  for (const p of await queuedPunches()) await dropPunch(p.idempotency_key);
});

describe("kiosk offline punches", () => {
  it("seals the code and PIN so only the key holder can read them", async () => {
    const { publicKey, privateKey } = await keyPair();
    const punch = {
      c: "E-1",
      p: "4821",
      t: "check_in" as const,
      at: "2026-10-13T06:00:00Z",
      k: "k1",
    };

    const sealed = await sealPunch(publicKey, punch);

    expect(sealed).not.toContain("4821");
    expect(await open(privateKey, sealed)).toEqual(punch);
  });

  it("keeps a punch until the server answers for it", async () => {
    const { publicKey } = await keyPair();
    await savePunchOffline(
      { public_key: publicKey, grant: "g", issued_at: "" },
      { c: "E-1", p: null, t: "check_in", k: "k-wait" },
    );
    expect(await queuedPunches()).toHaveLength(1);

    // The connection is still down: nothing is lost.
    server.use(http.post("*/kiosk/sync", () => HttpResponse.error()));
    await expect(syncQueuedPunches("token")).rejects.toThrow();
    expect(await queuedPunches()).toHaveLength(1);

    // Back online: recorded, and gone from the terminal.
    let sent: unknown = null;
    server.use(
      http.post("*/kiosk/sync", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({
          results: [{ idempotency_key: "k-wait", status: "created" }],
        });
      }),
    );
    expect(await syncQueuedPunches("token")).toBe(1);
    expect(await queuedPunches()).toHaveLength(0);
    expect(sent).toMatchObject({
      records: [{ idempotency_key: "k-wait", grant: "g" }],
    });
  });

  it("drops a punch only when it was recorded or refused for good", async () => {
    const { publicKey } = await keyPair();
    const offline = { public_key: publicKey, grant: "g", issued_at: "" };
    for (const k of ["k-new", "k-dup", "k-final", "k-passing", "k-unmarked"]) {
      await savePunchOffline(offline, { c: "E-1", p: null, t: "check_in", k });
    }

    server.use(
      http.post("*/kiosk/sync", () =>
        HttpResponse.json({
          results: [
            { idempotency_key: "k-new", status: "created" },
            { idempotency_key: "k-dup", status: "duplicate" },
            {
              idempotency_key: "k-final",
              status: "error",
              detail: "Wrong PIN",
              final: true,
            },
            {
              idempotency_key: "k-passing",
              status: "error",
              detail: "Try again",
              final: false,
            },
            { idempotency_key: "k-unmarked", status: "error" },
          ],
        }),
      ),
    );

    // Only the two recorded punches count as sent.
    expect(await syncQueuedPunches("token")).toBe(2);
    const left = (await queuedPunches()).map((p) => p.idempotency_key).sort();
    expect(left).toEqual(["k-passing", "k-unmarked"]);
  });
});

describe("a kiosk on a connected but dead line", () => {
  it.each([AxiosError.ECONNABORTED, AxiosError.ETIMEDOUT])(
    "counts a request that timed out (%s) as unreachable, so the punch is kept offline",
    (code) => {
      // Built the way axios's own adapters build it: no response, a timeout
      // code. (jsdom's XHR does not honour a timeout under msw, so a real one
      // cannot be provoked here.) The page then saves the punch under the
      // same key, so one that did reach the server is a duplicate there, not
      // a second record.
      const error = new AxiosError(
        "timeout of 8000ms exceeded",
        code,
        undefined,
        {},
      );

      expect(axios.isAxiosError(error)).toBe(true);
      expect(error.response).toBeUndefined();
      expect(isUnreachable(error)).toBe(true);
    },
  );

  it("does not count an answer, even an error, as unreachable", async () => {
    server.use(
      http.post("http://dead.test/no", () =>
        HttpResponse.json({}, { status: 500 }),
      ),
    );
    const error = await axios.post("http://dead.test/no").catch((e) => e);

    expect(isUnreachable(error)).toBe(false);
  });
});
