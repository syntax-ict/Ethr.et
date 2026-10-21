import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { PaginationControls } from "@/components/shared/pagination-controls";

/**
 * Several lists held `const [page, setPage] = useState(1)`, sent `page` to the
 * API, and rendered nothing that could ever call `setPage` — so every record
 * past the first page was unreachable. ESLint surfaced them only as
 * "'setPage' is assigned a value but never used".
 */
describe("PaginationControls", () => {
  const meta = { current_page: 2, last_page: 5, per_page: 25, total: 120 };

  it("renders nothing when there is only one page", () => {
    const { container } = render(
      <PaginationControls
        meta={{ current_page: 1, last_page: 1 }}
        onPageChange={vi.fn()}
      />,
    );

    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when meta is absent", () => {
    const { container } = render(
      <PaginationControls meta={undefined} onPageChange={vi.fn()} />,
    );

    expect(container).toBeEmptyDOMElement();
  });

  it("reports the current position", () => {
    render(<PaginationControls meta={meta} onPageChange={vi.fn()} />);

    expect(screen.getByText("Page 2 of 5")).toBeInTheDocument();
  });

  it("moves forward and back", () => {
    const onPageChange = vi.fn();
    render(<PaginationControls meta={meta} onPageChange={onPageChange} />);

    fireEvent.click(screen.getByRole("button", { name: /next/i }));
    expect(onPageChange).toHaveBeenCalledWith(3);

    fireEvent.click(screen.getByRole("button", { name: /previous/i }));
    expect(onPageChange).toHaveBeenCalledWith(1);
  });

  it("cannot page before the first page", () => {
    render(
      <PaginationControls
        meta={{ current_page: 1, last_page: 3 }}
        onPageChange={vi.fn()}
      />,
    );

    expect(screen.getByRole("button", { name: /previous/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /next/i })).not.toBeDisabled();
  });

  it("cannot page past the last page", () => {
    render(
      <PaginationControls
        meta={{ current_page: 3, last_page: 3 }}
        onPageChange={vi.fn()}
      />,
    );

    expect(screen.getByRole("button", { name: /next/i })).toBeDisabled();
  });

  it("locks both controls while a page is loading", () => {
    render(<PaginationControls meta={meta} onPageChange={vi.fn()} disabled />);

    expect(screen.getByRole("button", { name: /previous/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /next/i })).toBeDisabled();
  });

  it("is exposed as a labelled navigation landmark", () => {
    render(<PaginationControls meta={meta} onPageChange={vi.fn()} />);

    expect(
      screen.getByRole("navigation", { name: /pagination/i }),
    ).toBeInTheDocument();
  });
});

/**
 * Seven lists drew their own pager, some saying "1–25 of 140" and others
 * "Page 1 of 6" (FRONTEND_REDESIGN_PLAN.md, Phase 3: one pager). The shared
 * one now says the range whenever the API sends it, so no list lost detail in
 * the move.
 */
describe("PaginationControls with a record range", () => {
  it("says which records are showing when the API sends the range", () => {
    render(
      <PaginationControls
        meta={{ current_page: 2, last_page: 6, total: 140, from: 26, to: 50 }}
        onPageChange={vi.fn()}
      />,
    );
    expect(screen.getByText("Showing 26 to 50 of 140")).toBeInTheDocument();
  });

  it("falls back to the page when the range is missing", () => {
    render(
      <PaginationControls
        meta={{
          current_page: 2,
          last_page: 6,
          total: 140,
          from: null,
          to: null,
        }}
        onPageChange={vi.fn()}
      />,
    );
    expect(screen.getByText("Page 2 of 6")).toBeInTheDocument();
  });
});

describe("translated placeholders", () => {
  it("does not let a short name eat the start of a longer one", async () => {
    const { translateStatic } = await import("@/lib/i18n/translations");
    // `:to` is a prefix of `:total`; given in this order it once printed "50tal".
    expect(
      translateStatic("x.missing", "en", "Showing :from to :to of :total", {
        from: 26,
        to: 50,
        total: 140,
      }),
    ).toBe("Showing 26 to 50 of 140");
  });
});
