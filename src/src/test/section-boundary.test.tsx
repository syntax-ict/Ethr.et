import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import {
  SectionBoundary,
  isChunkLoadError,
} from "@/components/shared/section-boundary";

function setNavigatorOnline(value: boolean) {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value,
  });
}

/** What Turbopack throws when a chunk cannot be fetched. */
function chunkError() {
  return Object.assign(
    new Error(
      "Failed to load chunk /_next/static/chunks/0nm411xf6dzvp.js from module 218146",
    ),
    { name: "ChunkLoadError" },
  );
}

const fail = { with: null as unknown };

function Section() {
  if (fail.with) throw fail.with;
  return <p>The charts</p>;
}

function Page() {
  return (
    <div>
      <h1>Dashboard</h1>
      <SectionBoundary>
        <Section />
      </SectionBoundary>
    </div>
  );
}

describe("isChunkLoadError", () => {
  it("knows a chunk that could not be fetched, by name or by message", () => {
    expect(isChunkLoadError(chunkError())).toBe(true);
    expect(
      isChunkLoadError(
        new TypeError("Failed to fetch dynamically imported module: /x.js"),
      ),
    ).toBe(true);
  });

  it("is false for an ordinary error", () => {
    expect(isChunkLoadError(new Error("boom"))).toBe(false);
    expect(isChunkLoadError(null)).toBe(false);
    expect(isChunkLoadError("Failed to load chunk")).toBe(false);
  });
});

describe("SectionBoundary", () => {
  const reload = vi.fn();
  const realLocation = window.location;

  beforeEach(() => {
    setNavigatorOnline(true);
    fail.with = null;
    reload.mockReset();
    Object.defineProperty(window, "location", {
      configurable: true,
      value: { ...realLocation, reload },
    });
    // React logs a caught render error; the tests expect them.
    vi.spyOn(console, "error").mockImplementation(() => {});
  });

  afterEach(() => {
    setNavigatorOnline(true);
    Object.defineProperty(window, "location", {
      configurable: true,
      value: realLocation,
    });
    vi.restoreAllMocks();
  });

  it("keeps the rest of the page when a section's code cannot be fetched offline", () => {
    setNavigatorOnline(false);
    fail.with = chunkError();
    render(<Page />);

    expect(
      screen.getByText("This part needs a connection."),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Reload the page when you are back online/),
    ).toBeInTheDocument();
    // The page itself is still there: this is what was lost on production.
    expect(
      screen.getByRole("heading", { name: "Dashboard" }),
    ).toBeInTheDocument();
    // Nothing to press while there is no connection.
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("offers a reload once the connection is back, not a retry in place", async () => {
    setNavigatorOnline(false);
    fail.with = chunkError();
    render(<Page />);

    setNavigatorOnline(true);
    await act(async () => {
      window.dispatchEvent(new Event("online"));
    });

    expect(screen.getByText("You are back online.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Reload" }));
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("offers a reload for code a deploy replaced, while online", () => {
    fail.with = chunkError();
    render(<Page />);

    expect(
      screen.getByText("Reload the page to show this part."),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Reload" })).toBeInTheDocument();
  });

  it("tries an ordinary error again in place", () => {
    fail.with = new Error("boom");
    render(<Page />);
    expect(
      screen.getByText("This part could not be shown."),
    ).toBeInTheDocument();

    fail.with = null;
    fireEvent.click(screen.getByRole("button", { name: /try again/i }));

    expect(screen.getByText("The charts")).toBeInTheDocument();
    expect(reload).not.toHaveBeenCalled();
  });
});
