import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { LoginForm } from "@/app/(auth)/login/login-form";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

function renderWithQuery(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

// Separate from find-organisation.test.tsx so it runs, and fails on its own
// account, against code that has no find-organisation page to import.
describe("the apex login", () => {
  it("offers the way to find an organisation where it asks for a subdomain", async () => {
    renderWithQuery(<LoginForm />);

    const link = await screen.findByRole("link", {
      name: /find your organisation by email/i,
    });
    expect(link).toHaveAttribute("href", "/login/find");
  });
});
