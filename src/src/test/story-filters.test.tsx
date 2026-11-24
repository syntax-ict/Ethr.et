import { afterEach, describe, expect, it } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { useStoryFilters } from "@/features/news/components/story-browser";

/**
 * Assumption (f) of the news upgrade: one `useQueryFilters` instance carries
 * the search, the category and the page together with the page's own `read`,
 * and leaves every other parameter of the address as it found it.
 */
afterEach(() => window.history.replaceState(null, "", "/"));

describe("useStoryFilters", () => {
  it("carries q, category, page and read together and keeps org", () => {
    window.history.replaceState(null, "", "/announcements?org=acme&read=ANN1");
    const { result } = renderHook(() =>
      useStoryFilters({ extra: { read: "" } }),
    );
    expect(result.current.values.read).toBe("ANN1");

    act(() =>
      result.current.change({ q: " holiday ", category: "notice", page: 3 }),
    );

    const params = new URLSearchParams(window.location.search);
    expect(Object.fromEntries(params)).toEqual({
      org: "acme",
      read: "ANN1",
      q: "holiday",
      category: "notice",
      page: "3",
    });
    expect(result.current.query).toEqual({
      q: "holiday",
      category: "notice",
      page: 3,
    });

    act(() => result.current.set({ read: "" }));
    expect(window.location.search).not.toContain("read=");
    expect(window.location.search).toContain("page=3");
  });

  it("falls back for a category or page the address should not carry", () => {
    window.history.replaceState(null, "", "/news?category=bogus&page=-2&q=x");
    const { result } = renderHook(() => useStoryFilters());

    expect(result.current.query).toEqual({ q: "x", category: null, page: 1 });
  });

  it("clamps a long search to 100 characters", () => {
    window.history.replaceState(null, "", `/news?q=${"a".repeat(150)}`);
    const { result } = renderHook(() => useStoryFilters());

    expect(result.current.query.q).toHaveLength(100);
  });

  it("pushes a history entry per change only when asked to", () => {
    window.history.replaceState(null, "", "/news");
    const before = window.history.length;
    const { result } = renderHook(() => useStoryFilters({ push: true }));

    act(() => result.current.change({ category: "event", page: 1 }));
    expect(window.history.length).toBe(before + 1);
  });
});
