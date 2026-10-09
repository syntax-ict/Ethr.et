import { describe, expect, it } from "vitest";
import {
  MFA_ENROLMENT_PATH,
  MFA_ENROLMENT_REQUIRED,
  mfaEnrolmentRedirect,
} from "@/api/client";
import { BACKGROUND_HEADER, backgroundRequest } from "@/api/background";

/**
 * Audit N6. The API now enforces a tenant's MFA policy and idle timeout; these
 * pin the two client halves: where an enrolment refusal sends the browser, and
 * how a poll says it is not the user.
 */

function refusal(status: number, type: string) {
  return {
    response: { status, data: { type, title: "", status, detail: "" } },
  } as unknown as Parameters<typeof mfaEnrolmentRedirect>[0];
}

describe("mfaEnrolmentRedirect", () => {
  it("sends an enrolment refusal to the two-factor setup screen", () => {
    expect(
      mfaEnrolmentRedirect(refusal(403, MFA_ENROLMENT_REQUIRED), "/employees"),
    ).toBe(`${MFA_ENROLMENT_PATH}?mfa=required`);
  });

  it("does not redirect from the setup screen itself", () => {
    // Its shell still makes requests the API refuses; redirecting would
    // reload the page forever.
    expect(
      mfaEnrolmentRedirect(
        refusal(403, MFA_ENROLMENT_REQUIRED),
        "/profile/security/",
      ),
    ).toBeNull();
  });

  it("leaves every other refusal alone", () => {
    expect(
      mfaEnrolmentRedirect(
        refusal(403, "https://ethr.et/errors/forbidden"),
        "/employees",
      ),
    ).toBeNull();
    expect(
      mfaEnrolmentRedirect(
        refusal(403, "https://ethr.et/errors/mfa-incomplete"),
        "/employees",
      ),
    ).toBeNull();
    expect(
      mfaEnrolmentRedirect(refusal(401, MFA_ENROLMENT_REQUIRED), "/employees"),
    ).toBeNull();
  });
});

describe("backgroundRequest", () => {
  it("marks a poll with the header the API's idle timeout reads", () => {
    expect(BACKGROUND_HEADER).toBe("X-ETHR-Background");
    expect(backgroundRequest()).toEqual({
      headers: { [BACKGROUND_HEADER]: "1" },
    });
  });

  it("adds nothing to a request that is the user's own", () => {
    expect(backgroundRequest(false)).toEqual({});
  });
});
