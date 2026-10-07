import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { LoginForm } from "@/app/(auth)/login/login-form";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

function renderAt(url: string) {
  window.history.replaceState({}, "", url);
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <LoginForm />
    </QueryClientProvider>,
  );
}

// ethr.et/{slug} redirects to /login?org={slug} (OrganisationEntryController).
// Without wildcard subdomains that redirect is how an organisation's people
// arrive, so the field has to arrive filled in.
describe("the login page reached from an organisation's entry URL", () => {
  afterEach(() => {
    localStorage.clear();
    window.history.replaceState({}, "", "/");
  });

  it("prefills the organisation named by ?org=", async () => {
    renderAt("/login?org=acme");

    expect(await screen.findByLabelText(/organi[sz]ation/i)).toHaveValue(
      "acme",
    );
  });

  it("prefers ?org= over the organisation this browser last used", async () => {
    localStorage.setItem("tenant", "habru");
    renderAt("/login?org=acme");

    expect(await screen.findByLabelText(/organi[sz]ation/i)).toHaveValue(
      "acme",
    );
  });

  it("stores nothing until the person signs in", async () => {
    localStorage.setItem("tenant", "habru");
    renderAt("/login?org=acme");

    await screen.findByLabelText(/organi[sz]ation/i);
    expect(localStorage.getItem("tenant")).toBe("habru");
  });

  it("ignores a value that is not an organisation slug", async () => {
    localStorage.setItem("tenant", "habru");
    renderAt("/login?org=%3Cscript%3E");

    expect(await screen.findByLabelText(/organi[sz]ation/i)).toHaveValue(
      "habru",
    );
  });
});
