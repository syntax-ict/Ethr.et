import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { SearchInput } from "@/components/shared/search-input";

afterEach(() => {
  vi.useRealTimers();
});

// The debounce re-armed whenever `onChange` changed identity — every render,
// for the inline arrows every caller passes — and fired the unchanged text.
// Callers reset to page 1 on a search, so Next on four paged lists bounced
// back to page 1 300 ms later (audit N92).
describe("<SearchInput>", () => {
  it("emits nothing while the text is unchanged, however often it re-renders", () => {
    vi.useFakeTimers();
    const calls: string[] = [];
    const { rerender } = render(
      <SearchInput value="" onChange={(v) => calls.push(v)} />,
    );
    for (let i = 0; i < 5; i++) {
      rerender(<SearchInput value="" onChange={(v) => calls.push(v)} />);
      act(() => {
        vi.advanceTimersByTime(400);
      });
    }

    expect(calls).toEqual([]);
  });

  it("emits typed text once, after the debounce", () => {
    vi.useFakeTimers();
    const calls: string[] = [];
    render(<SearchInput value="" onChange={(v) => calls.push(v)} />);

    fireEvent.change(screen.getByRole("textbox"), {
      target: { value: "abe" },
    });
    act(() => {
      vi.advanceTimersByTime(299);
    });
    expect(calls).toEqual([]);
    act(() => {
      vi.advanceTimersByTime(1);
    });

    expect(calls).toEqual(["abe"]);
  });
});
