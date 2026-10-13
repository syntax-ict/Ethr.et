import "fake-indexeddb/auto";
import { describe, it, expect, beforeEach } from "vitest";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  dropPunch,
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
});
