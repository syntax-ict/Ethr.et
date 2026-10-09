import { describe, expect, it } from "vitest";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";

const GENERIC_422 = "The given data was invalid.";

function failure(data: Record<string, unknown>) {
  return { response: { data } };
}

describe("apiErrorMessage", () => {
  it("shows the field's reason, not the generic 422 detail", () => {
    // The device form showed `detail`, so a private address was "invalid"
    // with no reason (audit N53).
    const err = failure({
      detail: GENERIC_422,
      errors: {
        "connection_config.host": [
          "Private and internal network addresses are not allowed.",
        ],
      },
    });

    expect(apiErrorMessage(err, "Could not add device")).toBe(
      "Private and internal network addresses are not allowed.",
    );
  });

  it("shows the detail when there are no field errors, as a 409 has", () => {
    const err = failure({
      detail: "This rotation still has 2 current or upcoming assignments.",
    });

    expect(apiErrorMessage(err, "Could not delete rotation")).toBe(
      "This rotation still has 2 current or upcoming assignments.",
    );
  });

  it("falls back when the response says nothing, as a network failure does", () => {
    expect(apiErrorMessage(new Error("Network Error"), "Save failed")).toBe(
      "Save failed",
    );
  });
});

// The same choice without a fallback, for screens that pick their own:
// seventeen read `response.data.detail` by hand, so a 422 showed the generic
// "The given data was invalid." instead of the field's reason (redundancy
// audit, 2026-10-09).
describe("apiErrorDetail", () => {
  it("prefers the first field error over the generic detail", () => {
    const err = {
      response: {
        data: {
          detail: "The given data was invalid.",
          errors: { date: ["The date is a holiday."] },
        },
      },
    };
    expect(apiErrorDetail(err)).toBe("The date is a holiday.");
  });

  it("falls back to detail, then to nothing", () => {
    expect(apiErrorDetail({ response: { data: { detail: "Locked." } } })).toBe(
      "Locked.",
    );
    expect(apiErrorDetail(new Error("network"))).toBeUndefined();
  });
});
