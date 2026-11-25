import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { LEGAL_VERSION, privacyEn, termsEn } from "@/lib/legal/documents";

/**
 * The version a sign-up accepts is recorded by the server
 * (`LegalDocuments::VERSION`, written to `tenants.terms_accepted_version`),
 * while the documents people read live here. If one moves without the other,
 * every new organisation is recorded as accepting a version it was never
 * shown, and nothing else would notice.
 */
describe("the accepted legal version", () => {
  it("is the same on the server as on the pages people read", () => {
    const php = readFileSync(
      resolve(__dirname, "../../../api/app/Support/LegalDocuments.php"),
      "utf8",
    );
    const match = php.match(/const VERSION = '([^']+)';/);

    expect(match?.[1]).toBe(LEGAL_VERSION);
  });

  it("is the effective date both documents carry", () => {
    expect(termsEn.effectiveDate).toBe(LEGAL_VERSION);
    expect(privacyEn.effectiveDate).toBe(LEGAL_VERSION);
  });
});
