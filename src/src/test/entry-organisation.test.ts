import { describe, expect, it } from "vitest";
import { entryOrganisation } from "@/lib/auth/entry-organisation";

/**
 * ethr.et/{slug} is served the shared sign-in page; the page finds its
 * organisation in the path. Only a lone slug counts.
 */
describe("entryOrganisation", () => {
  it("reads the organisation from ethr.et/{slug}", () => {
    expect(entryOrganisation("/acme")).toBe("acme");
    expect(entryOrganisation("/Acme-Manufacturing/")).toBe(
      "acme-manufacturing",
    );
  });

  it("is not fooled by the sign-in page's own address or deeper paths", () => {
    expect(entryOrganisation("/login")).toBeNull();
    expect(entryOrganisation("/login/find")).toBeNull();
    expect(entryOrganisation("/acme/extra")).toBeNull();
    expect(entryOrganisation("/")).toBeNull();
    expect(entryOrganisation(null)).toBeNull();
  });

  it("ignores anything that is not a slug", () => {
    expect(entryOrganisation("/-acme")).toBeNull();
    expect(entryOrganisation("/a%20b")).toBeNull();
    expect(entryOrganisation("/" + "a".repeat(64))).toBeNull();
  });
});
