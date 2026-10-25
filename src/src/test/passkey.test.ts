import { describe, expect, it } from "vitest";
import { fixedCoordinate, offlineChallenge } from "@/lib/attendance/passkey";

/**
 * The phone and the server must hash an offline punch to the same bytes, or
 * every signed offline punch fails at sync. The expected values are what
 * OfflineGrant::proofChallenge computes in PHP for the same fields.
 */
describe("offline punch challenge", () => {
  it("matches the server's challenge for a punch with a position", async () => {
    await expect(
      offlineChallenge("nOnCe-123_x", {
        idempotency_key: "mobile-1-abc",
        type: "check_in",
        captured_at: "2026-10-26T05:20:00.000Z",
        latitude: 9.0108,
        longitude: 38.7613,
      }),
    ).resolves.toBe("9vEVxUooXvvb1KI8Li0kcZ8HJB8SV5VJqeSqaSU4AyA");
  });

  it("matches the server's challenge for a punch without one", async () => {
    await expect(
      offlineChallenge("n", {
        idempotency_key: "k",
        type: "check_out",
        captured_at: "t",
      }),
    ).resolves.toBe("zDtn96Ie84jJu-0OhFxLYK8X0wPOvJA4PUMTDR369_E");
  });

  it("changes when any signed field is edited", async () => {
    const fields = {
      idempotency_key: "mobile-1-abc",
      type: "check_in",
      captured_at: "2026-10-26T05:20:00.000Z",
      latitude: 9.0108,
      longitude: 38.7613,
    };
    const signed = await offlineChallenge("nOnCe-123_x", fields);

    await expect(
      offlineChallenge("nOnCe-123_x", { ...fields, latitude: 9.2 }),
    ).resolves.not.toBe(signed);
    await expect(
      offlineChallenge("nOnCe-123_x", {
        ...fields,
        captured_at: "2026-10-26T04:00:00.000Z",
      }),
    ).resolves.not.toBe(signed);
  });

  it("rounds coordinates to the six decimals both sides hash", () => {
    expect(fixedCoordinate(9.01080049)).toBe(9.0108);
    expect(fixedCoordinate(38.761349999)).toBe(38.76135);
  });
});
