import { describe, it, expect, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { useSavedViews } from "@/lib/hooks/useSavedViews";

interface FixtureState {
  search: string;
}

describe("useSavedViews", () => {
  beforeEach(() => {
    localStorage.removeItem("test:views");
  });

  it("starts empty", () => {
    const { result } = renderHook(() =>
      useSavedViews<FixtureState>("test:views"),
    );
    expect(result.current.views).toEqual([]);
  });

  it("saves a named view and persists it to localStorage", () => {
    const { result } = renderHook(() =>
      useSavedViews<FixtureState>("test:views"),
    );

    act(() => result.current.save("Engineering", { search: "engineering" }));

    expect(result.current.views).toHaveLength(1);
    expect(result.current.views[0]).toMatchObject({
      name: "Engineering",
      state: { search: "engineering" },
    });
    expect(result.current.views[0].id).toBeTruthy();

    const stored = JSON.parse(localStorage.getItem("test:views") ?? "[]");
    expect(stored).toHaveLength(1);
    expect(stored[0].name).toBe("Engineering");
  });

  it("removes a view by id, leaving the others", () => {
    const { result } = renderHook(() =>
      useSavedViews<FixtureState>("test:views"),
    );

    act(() => result.current.save("A", { search: "a" }));
    act(() => result.current.save("B", { search: "b" }));
    const idToRemove = result.current.views[0].id;

    act(() => result.current.remove(idToRemove));

    expect(result.current.views).toHaveLength(1);
    expect(result.current.views[0].name).toBe("B");
  });
});
