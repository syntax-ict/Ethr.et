import { describe, it, expect, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { useT } from "@/lib/i18n/useT";

/**
 * `useT` re-renders once the reader's dictionary is in memory. That re-render
 * reaches a `useMemo` keyed on `t` (the sidebar's nav is one) only if `t` is a
 * new function: with the same one, the memo kept the fallbacks it computed
 * before the strings arrived, and an English dashboard switched to Amharic kept
 * an English sidebar (2026-10-31). And it must happen only then: a new `t` on
 * every mount rebuilt every table's columns and remounted their row buttons.
 */
describe("useT", () => {
  it("keeps the same t when the dictionary was already there", async () => {
    // The test setup registers en and am whole.
    const { result } = renderHook(() => useT());
    const first = result.current.t;

    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(result.current.t).toBe(first);
  });

  it("hands out a new t when the dictionary arrives after the render", async () => {
    // A fresh copy of the i18n modules, with nothing registered.
    vi.resetModules();
    const fresh = await import("@/lib/i18n/useT");
    const { hasLocale } = await import("@/lib/i18n/translations");
    expect(hasLocale("en")).toBe(false);

    const { result } = renderHook(() => fresh.useT());
    const before = result.current.t;
    expect(before("work_sites.title", "fallback")).toBe("fallback");

    await waitFor(() => expect(result.current.t).not.toBe(before));
    expect(result.current.t("work_sites.title", "fallback")).toBe("Work sites");
  });
});
