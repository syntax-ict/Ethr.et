import { afterEach, describe, expect, it, vi } from "vitest";
import { act, render } from "@testing-library/react";
import { revealClass, useRevealOnce } from "@/lib/hooks/useRevealOnce";

function Probe() {
  const { ref, state } = useRevealOnce<HTMLDivElement>();
  return (
    <div ref={ref} data-state={state} className={revealClass(state)}>
      content
    </div>
  );
}

type Callback = (entries: Array<{ isIntersecting: boolean }>) => void;

function installObserver(): {
  trigger: (visible: boolean) => void;
  disconnect: ReturnType<typeof vi.fn>;
} {
  let callback: Callback = () => {};
  const disconnect = vi.fn();
  class FakeObserver {
    constructor(cb: Callback) {
      callback = cb;
    }
    observe() {}
    unobserve() {}
    disconnect = disconnect;
  }
  vi.stubGlobal("IntersectionObserver", FakeObserver);
  return {
    trigger: (visible) => callback([{ isIntersecting: visible }]),
    disconnect,
  };
}

function placeBelowViewport() {
  vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({
    top: 5000,
    bottom: 5100,
    left: 0,
    right: 0,
    width: 0,
    height: 0,
    x: 0,
    y: 5000,
    toJSON: () => ({}),
  });
}

function matchMedia(reduced: boolean) {
  vi.stubGlobal(
    "matchMedia",
    vi.fn().mockImplementation((query: string) => ({
      matches: reduced && query.includes("reduce"),
      media: query,
      addEventListener() {},
      removeEventListener() {},
    })),
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("useRevealOnce", () => {
  it("starts settled, so the prerendered HTML hides nothing", () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    const { container } = render(<Probe />);
    expect(container.firstElementChild).toHaveAttribute(
      "data-state",
      "settled",
    );
    expect(container.firstElementChild).not.toHaveClass("ethr-reveal-hidden");
  });

  it("hides an element below the fold after hydration, then reveals it once", () => {
    const { trigger, disconnect } = installObserver();
    placeBelowViewport();
    matchMedia(false);

    const { container } = render(<Probe />);
    // The observer reports the element below the fold: only now is it hidden.
    act(() => trigger(false));
    expect(container.firstElementChild).toHaveClass("ethr-reveal-hidden");

    act(() => trigger(true));
    expect(container.firstElementChild).toHaveClass("ethr-reveal-in");
    expect(disconnect).toHaveBeenCalled();

    // A second intersection changes nothing: it played once.
    act(() => trigger(false));
    act(() => trigger(true));
    expect(container.firstElementChild).toHaveClass("ethr-reveal-in");
  });

  it("never hides an element that is already on screen", () => {
    const { trigger, disconnect } = installObserver();
    matchMedia(false);

    const { container } = render(<Probe />);
    // The observer's first report: already visible, so nothing to reveal.
    act(() => trigger(true));
    expect(disconnect).toHaveBeenCalled();
    expect(container.firstElementChild).toHaveAttribute(
      "data-state",
      "settled",
    );
  });

  it("never hides anything for a reader who prefers reduced motion", () => {
    const { trigger } = installObserver();
    placeBelowViewport();
    matchMedia(true);

    const { container } = render(<Probe />);
    // Nothing is observed, so a report never arrives; this one is a no-op.
    act(() => trigger(false));
    expect(container.firstElementChild).toHaveAttribute(
      "data-state",
      "settled",
    );
    expect(container.firstElementChild).not.toHaveClass("ethr-reveal-hidden");
  });
});
