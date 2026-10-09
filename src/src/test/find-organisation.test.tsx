import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { FindOrganisationForm } from "@/app/(auth)/login/find/find-organisation-form";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

// "Find my organisation" on the apex login. The page says one sentence to
// everyone and shows nothing about which organisations an address belongs to;
// the links go to the inbox.

const SENT =
  "If that address belongs to an organisation, we've emailed you its sign-in link.";

function renderWithQuery(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

async function submitEmail(email = "person@example.et") {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/email/i), email);
  await user.click(screen.getByRole("button", { name: /email me the link/i }));
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("find my organisation", () => {
  it("posts the address and answers with the one sentence", async () => {
    let body: unknown = null;
    server.use(
      http.post("*/api/v1/auth/find-organisation", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ message: SENT });
      }),
    );
    renderWithQuery(<FindOrganisationForm />);

    await submitEmail();

    expect(await screen.findByText(SENT)).toBeInTheDocument();
    expect(body).toEqual({ email: "person@example.et" });
  });

  it("shows its own sentence, never text from the response", async () => {
    // Even if the API were ever to say more, the page must not echo it: what an
    // address belongs to stays in the inbox.
    server.use(
      http.post("*/api/v1/auth/find-organisation", () =>
        HttpResponse.json({ message: "Acme Ltd, Habru Textiles" }),
      ),
    );
    renderWithQuery(<FindOrganisationForm />);

    await submitEmail();

    expect(await screen.findByText(SENT)).toBeInTheDocument();
    expect(screen.queryByText(/Acme|Habru/)).toBeNull();
  });

  it("says when the address has been asked about too often", async () => {
    server.use(
      http.post("*/api/v1/auth/find-organisation", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/rate-limit",
            title: "Too Many Requests",
            status: 429,
            detail: "x",
          },
          { status: 429 },
        ),
      ),
    );
    renderWithQuery(<FindOrganisationForm />);

    await submitEmail();

    expect(
      await screen.findByText(
        "Too many requests for this address. Try again in a few minutes.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByText(SENT)).toBeNull();
  });
});
