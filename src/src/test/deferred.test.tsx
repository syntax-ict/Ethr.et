import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { Deferred } from "@/components/shared/deferred";

/**
 * The side column's widgets wait for the reader to come near before they
 * mount, and so before they fetch (dashboard review, 2026-11-03, PR 11).
 * Mounting is what fetches, so these pin when the children mount.
 */
const observers: Array<{
  callback: IntersectionObserverCallback;
  margin: string | undefined;
  disconnected: boolean;
}> = [];

class FakeIntersectionObserver {
  private entry: (typeof observers)[number];
  constructor(
    callback: IntersectionObserverCallback,
    options?: IntersectionObserverInit,
  ) {
    this.entry = {
      callback,
      margin: options?.rootMargin,
      disconnected: false,
    };
    observers.push(this.entry);
  }
  observe() {}
  unobserve() {}
  disconnect() {
    this.entry.disconnected = true;
  }
  takeRecords() {
    return [];
  }
}

function comeNear() {
  act(() => {
    for (const o of observers) {
      if (o.disconnected) continue;
      o.callback(
        [{ isIntersecting: true } as IntersectionObserverEntry],
        {} as IntersectionObserver,
      );
    }
  });
}

beforeEach(() => {
  observers.length = 0;
  vi.stubGlobal("IntersectionObserver", FakeIntersectionObserver);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("<Deferred>", () => {
  it("shows the placeholder until the reader comes near, then the content", () => {
    render(
      <Deferred placeholder={<div data-testid="placeholder" />}>
        <div data-testid="content" />
      </Deferred>,
    );

    expect(screen.getByTestId("placeholder")).toBeInTheDocument();
    expect(screen.queryByTestId("content")).toBeNull();
    expect(observers[0].margin).toBe("200px");

    comeNear();

    expect(screen.getByTestId("content")).toBeInTheDocument();
    expect(screen.queryByTestId("placeholder")).toBeNull();
    expect(observers[0].disconnected).toBe(true);
  });

  it("takes a wider margin for something heavy", () => {
    render(
      <Deferred margin="600px" placeholder={null}>
        <div data-testid="content" />
      </Deferred>,
    );

    expect(observers[0].margin).toBe("600px");
  });

  it("mounts straight away in a browser without IntersectionObserver", async () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    render(
      <Deferred placeholder={<div data-testid="placeholder" />}>
        <div data-testid="content" />
      </Deferred>,
    );

    expect(await screen.findByTestId("content")).toBeInTheDocument();
  });
});
