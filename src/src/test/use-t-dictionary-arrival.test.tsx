import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { useT } from "@/lib/i18n/useT";

/**
 * `useT` re-renders once the reader's dictionary is in memory. That re-render
 * reaches a `useMemo` keyed on `t` (the sidebar's nav is one) only if `t` is a
 * new function: with the same one, the memo kept the fallbacks it computed
 * before the strings arrived, and an English dashboard switched to Amharic kept
 * an English sidebar (2026-10-31).
 */
describe("useT when a dictionary arrives", () => {
  it("hands out a new t, so memoized text is computed again", async () => {
    const { result } = renderHook(() => useT());
    const before = result.current.t;

    await waitFor(() => expect(result.current.t).not.toBe(before));
    expect(result.current.locale).toBe("en");
  });
});
