import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { FindOrganisationForm } from "@/app/(auth)/login/find/find-organisation-form";
import { ResetPasswordForm } from "@/app/(auth)/login/reset/reset-form";

let search = new URLSearchParams();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => search,
}));

/**
 * Sanctum refuses a signed-out POST that carries no XSRF cookie, with a 419.
 * These pages are usually the first a browser opens — the reset and account
 * activation links arrive by email — so nothing has fetched the cookie yet.
 * They did not fetch it either: the production-shaped rehearsal answered every
 * reset and every find-my-organisation with 419 (2026-10-09), which meant no
 * invited user could activate an account. Each must ask for the cookie first.
 */
let calls: string[] = [];

beforeEach(() => {
  calls = [];
  search = new URLSearchParams();
  localStorage.clear();
  localStorage.setItem("locale", "en");
  server.use(
    http.get("*/sanctum/csrf-cookie", () => {
      calls.push("csrf");
      return new HttpResponse(null, { status: 204 });
    }),
  );
});

function renderWithQuery(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

describe("signed-out forms fetch the CSRF cookie before they post", () => {
  it("find my organisation", async () => {
    server.use(
      http.post("*/api/v1/auth/find-organisation", () => {
        calls.push("post");
        return HttpResponse.json({ message: "ok" });
      }),
    );
    renderWithQuery(<FindOrganisationForm />);

    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/email/i), "person@example.et");
    await user.click(
      screen.getByRole("button", { name: /email me the link/i }),
    );

    await waitFor(() => expect(calls).toEqual(["csrf", "post"]));
  });

  it("reset password, which is also where account activation lands", async () => {
    search = new URLSearchParams({
      token: "t".repeat(64),
      email: "new.hire@acme.et",
      tenant: "acme",
    });
    server.use(
      http.post("*/api/v1/auth/password/reset", () => {
        calls.push("post");
        return HttpResponse.json({ message: "ok" });
      }),
    );
    renderWithQuery(<ResetPasswordForm />);

    const user = userEvent.setup();
    const password = "Correct-Horse-Battery-9";
    await user.type(screen.getByLabelText("New password"), password);
    await user.type(screen.getByLabelText("Confirm password"), password);
    await user.click(screen.getByRole("button", { name: "Reset password" }));

    await waitFor(() => expect(calls).toEqual(["csrf", "post"]));
  });
});
