import { describe, it, expect } from "vitest";
import { givenName } from "@/lib/given-name";

describe("givenName", () => {
  it("takes the first word of an Ethiopian name", () => {
    expect(givenName("Abebe Kebede Tesfaye")).toBe("Abebe");
    expect(givenName("አበበ ከበደ")).toBe("አበበ");
  });

  it("skips a leading title in English or Amharic", () => {
    expect(givenName("Prof. Elisabeth Cummings")).toBe("Elisabeth");
    expect(givenName("Ms. Madilyn Wehner")).toBe("Madilyn");
    expect(givenName("Dr Sara Tesfaye")).toBe("Sara");
    expect(givenName("W/ro Almaz Haile")).toBe("Almaz");
    expect(givenName("ወ/ሮ አልማዝ ኃይሌ")).toBe("አልማዝ");
    expect(givenName("ዶ/ር ሳራ ተስፋዬ")).toBe("ሳራ");
  });

  it("keeps a lone title rather than greeting nobody", () => {
    expect(givenName("Dr.")).toBe("Dr.");
  });

  it("has nothing for an empty name", () => {
    expect(givenName(null)).toBeUndefined();
    expect(givenName("  ")).toBeUndefined();
  });
});
