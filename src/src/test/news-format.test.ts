import { describe, expect, it } from "vitest";
import { withoutMarkup } from "@/features/news/news-format";

/**
 * The editor's preview applies the server's markup rule
 * (`PlainExcerpt::withoutMarkup`), so these mirror the server's own cases.
 */
describe("withoutMarkup", () => {
  it("removes tags and keeps a < that opens none", () => {
    expect(withoutMarkup("Budget<2000 birr\n\nSecond <b>para</b>")).toBe(
      "Budget<2000 birr\n\nSecond para",
    );
  });

  it("leaves text with no tag in it unchanged", () => {
    expect(withoutMarkup("I <3 you, ok")).toBe("I <3 you, ok");
    expect(withoutMarkup("a < b and c > d")).toBe("a < b and c > d");
  });

  it("removes a script tag and keeps what it wrapped, as text", () => {
    expect(withoutMarkup("<script>alert(1)</script>hi")).toBe("alert(1)hi");
  });

  it("removes comments, including across lines", () => {
    expect(withoutMarkup("a<!-- hidden\nline -->b")).toBe("ab");
  });

  it("repeats until nothing changes, so one removal cannot assemble another tag", () => {
    expect(withoutMarkup("<scr<b>ipt>x")).toBe("x");
    expect(withoutMarkup("<<b>b>text")).toBe("text");
  });

  it("is a no-op on its own output", () => {
    const once = withoutMarkup("x <i>y</i> <!-- z --> <3");
    expect(withoutMarkup(once)).toBe(once);
  });
});
