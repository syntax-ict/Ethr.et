import { describe, it, expect, vi } from "vitest";
import { renderHook } from "@testing-library/react";
import { useUnsavedChangesWarning } from "@/lib/hooks/useUnsavedChangesWarning";

function dispatchBeforeUnload() {
  const event = new Event("beforeunload", { cancelable: true }) as BeforeUnloadEvent;
  window.dispatchEvent(event);
  return event;
}

describe("useUnsavedChangesWarning", () => {
  it("does not intercept beforeunload when there are no unsaved changes", () => {
    renderHook(() => useUnsavedChangesWarning(false));

    const event = dispatchBeforeUnload();
    expect(event.defaultPrevented).toBe(false);
  });

  it("intercepts beforeunload when there are unsaved changes", () => {
    renderHook(() => useUnsavedChangesWarning(true));

    const event = dispatchBeforeUnload();
    expect(event.defaultPrevented).toBe(true);
  });

  it("stops intercepting once changes are marked saved", () => {
    const { rerender } = renderHook(
      ({ dirty }) => useUnsavedChangesWarning(dirty),
      { initialProps: { dirty: true } },
    );

    rerender({ dirty: false });

    const event = dispatchBeforeUnload();
    expect(event.defaultPrevented).toBe(false);
  });

  it("removes the listener on unmount", () => {
    const removeSpy = vi.spyOn(window, "removeEventListener");
    const { unmount } = renderHook(() => useUnsavedChangesWarning(true));

    unmount();

    expect(removeSpy).toHaveBeenCalledWith("beforeunload", expect.any(Function));
    removeSpy.mockRestore();
  });
});
