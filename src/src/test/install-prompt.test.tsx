import { describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import {
  listenForInstallPrompt,
  promptInstall,
  useInstallPrompt,
} from "@/lib/hooks/useInstallPrompt";

/**
 * "Install app". Chrome offers installation once, through an event that can
 * fire before React mounts; the app holds on to it and shows its own button.
 */
describe("useInstallPrompt", () => {
  it("captures the browser's install event and shows its dialog on request", async () => {
    listenForInstallPrompt();
    const { result } = renderHook(() => useInstallPrompt());
    expect(result.current.canPrompt).toBe(false);

    const prompt = vi.fn(async () => {});
    const event = Object.assign(new Event("beforeinstallprompt"), {
      prompt,
      userChoice: Promise.resolve({ outcome: "accepted" as const }),
    });
    const preventDefault = vi.spyOn(event, "preventDefault");

    act(() => {
      window.dispatchEvent(event);
    });

    // The browser's own mini-infobar is held back in favour of the app's.
    expect(preventDefault).toHaveBeenCalled();
    expect(result.current.canPrompt).toBe(true);

    let accepted = false;
    await act(async () => {
      accepted = await promptInstall();
    });

    expect(prompt).toHaveBeenCalledOnce();
    expect(accepted).toBe(true);
    // A captured prompt can be used once.
    expect(result.current.canPrompt).toBe(false);
  });

  it("stops offering once the app is installed", () => {
    listenForInstallPrompt();
    const { result } = renderHook(() => useInstallPrompt());

    act(() => {
      window.dispatchEvent(new Event("appinstalled"));
    });

    expect(result.current.installed).toBe(true);
    expect(result.current.isIos).toBe(false);
  });
});
