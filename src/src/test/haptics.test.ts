import { afterEach, describe, expect, it, vi } from "vitest";
import { haptic } from "@/lib/attendance/haptics";

const original = Object.getOwnPropertyDescriptor(navigator, "vibrate");

afterEach(() => {
  delete (navigator as { userActivation?: unknown }).userActivation;
  if (original) Object.defineProperty(navigator, "vibrate", original);
  else delete (navigator as { vibrate?: unknown }).vibrate;
});

describe("haptic", () => {
  it("vibrates with each pattern", () => {
    const vibrate = vi.fn().mockReturnValue(true);
    Object.defineProperty(navigator, "vibrate", {
      configurable: true,
      value: vibrate,
    });

    haptic("tap");
    haptic("ready");
    haptic("success");
    haptic("refused");

    expect(vibrate.mock.calls).toEqual([
      [10],
      [15],
      [[20, 40, 30]],
      [[30, 60, 30, 60, 30]],
    ]);
  });
  it("does not vibrate before the person has touched the page", () => {
    const vibrate = vi.fn();
    Object.defineProperty(navigator, "vibrate", {
      configurable: true,
      value: vibrate,
    });
    Object.defineProperty(navigator, "userActivation", {
      configurable: true,
      value: { hasBeenActive: false },
    });
    haptic("ready");
    expect(vibrate).not.toHaveBeenCalled();
  });

  it("vibrates once the page has been touched", () => {
    const vibrate = vi.fn();
    Object.defineProperty(navigator, "vibrate", {
      configurable: true,
      value: vibrate,
    });
    Object.defineProperty(navigator, "userActivation", {
      configurable: true,
      value: { hasBeenActive: true },
    });
    haptic("ready");
    expect(vibrate).toHaveBeenCalledWith(15);
  });

  it("vibrates where userActivation does not exist", () => {
    const vibrate = vi.fn();
    Object.defineProperty(navigator, "vibrate", {
      configurable: true,
      value: vibrate,
    });
    Object.defineProperty(navigator, "userActivation", {
      configurable: true,
      value: undefined,
    });
    haptic("tap");
    expect(vibrate).toHaveBeenCalledWith(10);
  });

  it("does nothing where the browser cannot vibrate", () => {
    Object.defineProperty(navigator, "vibrate", {
      configurable: true,
      value: undefined,
    });
    expect(() => haptic("tap")).not.toThrow();
  });

  it("does not throw when vibrate does", () => {
    Object.defineProperty(navigator, "vibrate", {
      configurable: true,
      value: () => {
        throw new Error("blocked");
      },
    });
    expect(() => haptic("success")).not.toThrow();
  });
});
