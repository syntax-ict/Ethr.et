import { describe, it, expect } from "vitest";

import {
  canonicalPhone,
  formatPhone,
  isEthiopianMobile,
  isInternationalPhone,
  telHref,
} from "@/lib/phone";
import { rules } from "@/lib/forms/rules";

describe("canonicalPhone", () => {
  it.each([
    "0911223344",
    "+251911223344",
    "251911223344",
    "911223344",
    "0911 22 33 44",
    "091-122-3344",
    "(0911) 223344",
    "+251 911 223 344",
  ])("reads %s as one number", (input) => {
    expect(canonicalPhone(input)).toBe("+251911223344");
  });

  it.each(["", "09112", "09112233445566", "0911 call me", "+44 20 7946 0958"])(
    "refuses %j",
    (input) => {
      expect(canonicalPhone(input)).toBeNull();
    },
  );
});

describe("isEthiopianMobile", () => {
  it("accepts the 9 and 7 ranges and refuses a landline", () => {
    expect(isEthiopianMobile("0911223344")).toBe(true);
    expect(isEthiopianMobile("+251 711 223 344")).toBe(true);
    expect(isEthiopianMobile("0111234567")).toBe(false);
  });
});

describe("isInternationalPhone", () => {
  it("accepts a foreign number with its country code", () => {
    expect(isInternationalPhone("+44 20 7946 0958")).toBe(true);
    expect(isInternationalPhone("+1 (415) 555-0132")).toBe(true);
  });

  it.each([
    "442079460958",
    "+44 call me",
    "+44 123",
    "+1234567890123456",
    "+251 91 122",
    "+251911223344",
  ])("refuses %j", (input) => {
    expect(isInternationalPhone(input)).toBe(false);
  });
});

describe("formatPhone", () => {
  it("groups every Ethiopian shape the same way", () => {
    expect(formatPhone("0911223344")).toBe("+251 91 122 3344");
    expect(formatPhone("+251911223344")).toBe("+251 91 122 3344");
    expect(formatPhone("0111234567")).toBe("+251 11 123 4567");
  });

  it("leaves a foreign number as entered and an empty value empty", () => {
    expect(formatPhone("+44 20 7946 0958")).toBe("+44 20 7946 0958");
    expect(formatPhone("")).toBe("");
    expect(formatPhone(null)).toBe("");
  });
});

describe("telHref", () => {
  it("gives the dialler a clean number", () => {
    expect(telHref("0911 22 33 44")).toBe("tel:+251911223344");
    expect(telHref("+44 20 7946 0958")).toBe("tel:+442079460958");
    expect(telHref("")).toBe("");
  });
});

describe("phone rules", () => {
  it("accepts spaced and local forms, as the server does", () => {
    expect(rules.phone().safeParse("0911 22 33 44").success).toBe(true);
    expect(rules.phone().safeParse("+251 911 223 344").success).toBe(true);
  });

  it("keeps phone() Ethiopian and mobilePhone() mobile-only", () => {
    expect(rules.phone().safeParse("+44 20 7946 0958").success).toBe(false);
    expect(rules.mobilePhone().safeParse("0111234567").success).toBe(false);
    expect(rules.mobilePhone().safeParse("0711 22 33 44").success).toBe(true);
  });

  it("lets a recorded number be foreign, and optional ones be empty", () => {
    expect(rules.anyPhone().safeParse("+44 20 7946 0958").success).toBe(true);
    expect(rules.anyPhone().safeParse("abc").success).toBe(false);
    expect(rules.optionalPhone().safeParse("").success).toBe(true);
    expect(rules.optionalPhone().safeParse("09112").success).toBe(false);
  });
});
