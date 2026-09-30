import { describe, expect, it } from "vitest";
import { escapeHtml } from "@/lib/utils/escape-html";

describe("escapeHtml", () => {
  it("neutralises markup so a name cannot inject script", () => {
    expect(escapeHtml('<img src=x onerror="alert(1)">')).toBe(
      "&lt;img src=x onerror=&quot;alert(1)&quot;&gt;",
    );
  });

  it("escapes ampersands first so entities are not double-decoded", () => {
    expect(escapeHtml("A & B <b>")).toBe("A &amp; B &lt;b&gt;");
    expect(escapeHtml("&lt;")).toBe("&amp;lt;");
  });

  it("leaves ordinary and Amharic text alone", () => {
    expect(escapeHtml("Abebe Kebede")).toBe("Abebe Kebede");
    expect(escapeHtml("አበበ ከበደ")).toBe("አበበ ከበደ");
  });
});
