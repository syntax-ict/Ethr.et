import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import {
  ProblemStory,
  STORY_SLUGS,
} from "@/components/marketing/landing/problem-stories";
import { marketingFixture } from "./fixtures/marketing-content";

type Callback = (entries: Array<{ isIntersecting: boolean }>) => void;

function installObserver(): { trigger: (visible: boolean) => void } {
  let callback: Callback = () => {};
  class FakeObserver {
    constructor(cb: Callback) {
      callback = cb;
    }
    observe() {}
    unobserve() {}
    disconnect() {}
  }
  vi.stubGlobal("IntersectionObserver", FakeObserver);
  return {
    trigger: (visible) => act(() => callback([{ isIntersecting: visible }])),
  };
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

const story = () =>
  document.querySelector<HTMLElement>(".ethr-story") as HTMLElement;

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("the problems ledger's stories", () => {
  it("has a story for every seeded problem", () => {
    const problems = marketingFixture().sections.find(
      (s) => s.kind === "problems",
    )!;
    expect(problems.items).toHaveLength(6);
    for (const item of problems.items) expect(STORY_SLUGS).toContain(item.slug);
  });

  it("prerenders the settled last frame, with no controls and nothing playing", () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    render(<ProblemStory slug="paper_attendance" />);

    expect(story()).toHaveAttribute("data-step", "7");
    expect(story()).not.toHaveAttribute("data-playing");
    expect(story().querySelector("svg")).toHaveAttribute("aria-hidden", "true");
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("stays on the settled frame for a reader who prefers reduced motion", () => {
    vi.useFakeTimers();
    matchMedia(true);
    const { trigger } = installObserver();
    render(<ProblemStory slug="manual_payroll" />);

    trigger(true);
    act(() => vi.advanceTimersByTime(6000));

    expect(story()).toHaveAttribute("data-step", "7");
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("plays from the beginning on screen, loops, and stops off screen", () => {
    vi.useFakeTimers();
    matchMedia(false);
    const { trigger } = installObserver();
    render(<ProblemStory slug="leave_spreadsheets" />);

    trigger(true);
    expect(story()).toHaveAttribute("data-step", "0");
    expect(story()).toHaveAttribute("data-playing");

    act(() => vi.advanceTimersByTime(1500 * 5));
    expect(story()).toHaveAttribute("data-step", "5");
    act(() => vi.advanceTimersByTime(1500 * 3));
    expect(story()).toHaveAttribute("data-step", "0");

    trigger(false);
    expect(story()).not.toHaveAttribute("data-playing");
    act(() => vi.advanceTimersByTime(1500 * 4));
    expect(story()).toHaveAttribute("data-step", "0");
  });

  it("pauses and plays again from its own control", () => {
    vi.useFakeTimers();
    matchMedia(false);
    const { trigger } = installObserver();
    render(<ProblemStory slug="contracts_expiring" />);
    trigger(true);
    act(() => vi.advanceTimersByTime(1500 * 2));

    fireEvent.click(
      screen.getByRole("button", { name: "Pause the animation" }),
    );
    act(() => vi.advanceTimersByTime(1500 * 3));
    expect(story()).toHaveAttribute("data-step", "2");

    // A paused story stays paused when it scrolls back into view.
    trigger(false);
    trigger(true);
    act(() => vi.advanceTimersByTime(1500 * 3));
    expect(story()).toHaveAttribute("data-step", "2");

    fireEvent.click(screen.getByRole("button", { name: "Play the animation" }));
    act(() => vi.advanceTimersByTime(1500));
    expect(story()).toHaveAttribute("data-step", "3");
  });

  it("draws every story's two halves, and a plain one for an unknown row", () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    for (const slug of [...STORY_SLUGS, "something_new"]) {
      const { unmount } = render(<ProblemStory slug={slug} />);
      expect(story()).toHaveAttribute("data-scene", `problems/${slug}`);
      // Parts for the hidden half are drawn and switched off, not omitted,
      // so they can transition in.
      expect(
        story().querySelectorAll('.ethr-story-part[data-on="true"]').length,
      ).toBeGreaterThan(0);
      expect(
        story().querySelectorAll('.ethr-story-part[data-on="false"]').length,
      ).toBeGreaterThan(0);
      unmount();
    }
  });
});
