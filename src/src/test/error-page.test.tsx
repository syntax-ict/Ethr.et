import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import ErrorPage from "@/app/error";

describe("Error page", () => {
  it("renders error message", () => {
    const error = new Error("Test error") as Error & { digest?: string };
    const reset = vi.fn();

    render(<ErrorPage error={error} reset={reset} />);
    expect(screen.getByText("Something went wrong")).toBeInTheDocument();
  });

  it("shows error digest when available", () => {
    const error = Object.assign(new Error("Test"), {
      digest: "err_abc123",
    });
    const reset = vi.fn();

    render(<ErrorPage error={error} reset={reset} />);
    expect(screen.getByText(/err_abc123/)).toBeInTheDocument();
  });

  it("calls reset on Try Again click", () => {
    const error = new Error("fail") as Error & { digest?: string };
    const reset = vi.fn();

    render(<ErrorPage error={error} reset={reset} />);
    fireEvent.click(screen.getByText("Try Again"));
    expect(reset).toHaveBeenCalledOnce();
  });

  it("has a dashboard link", () => {
    const error = new Error("fail") as Error & { digest?: string };
    const reset = vi.fn();

    render(<ErrorPage error={error} reset={reset} />);
    const link = screen.getByText("Go to Dashboard");
    expect(link.closest("a")).toHaveAttribute("href", "/dashboard");
  });
});
