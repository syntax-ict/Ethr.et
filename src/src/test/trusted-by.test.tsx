import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import userEvent from "@testing-library/user-event";
import { server } from "./msw/server";
import { TrustedBy } from "@/components/marketing/trusted-by";
import { ShowcaseCard } from "@/features/settings/components/showcase-card";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

/**
 * "Trusted by" on ETHR's public site (2026-10-18): only organisations that
 * agreed to be named, and an organisation's own switch for that consent.
 */
vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function withClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>{ui}</CalendarProvider>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
  localStorage.setItem("ethr.calendar", "gregorian");
});

describe("Trusted by section", () => {
  it("names each organisation, with its logo or its name, linking to its page", async () => {
    server.use(
      http.get("*/api/v1/trusted-by", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "T1",
              name: "Abay Bank",
              logo_url: "/api/v1/organisations/T1/logo/v1",
              url: "https://abay.ethr.et/",
            },
            {
              public_id: "T2",
              name: "Habesha Coffee",
              logo_url: null,
              url: "https://ethr.et/habesha",
            },
          ],
        }),
      ),
    );
    withClient(<TrustedBy />);

    expect(
      await screen.findByRole("heading", { name: "Trusted by" }),
    ).toBeInTheDocument();
    const logo = screen.getByRole("img", { name: "Abay Bank" });
    expect(logo).toHaveAttribute("src", "/api/v1/organisations/T1/logo/v1");
    expect(logo.closest("a")).toHaveAttribute("href", "https://abay.ethr.et/");
    expect(screen.getByText("Habesha Coffee").closest("a")).toHaveAttribute(
      "href",
      "https://ethr.et/habesha",
    );
  });

  it("draws nothing when no organisation may be named", async () => {
    let asked = false;
    server.use(
      http.get("*/api/v1/trusted-by", () => {
        asked = true;
        return HttpResponse.json({ data: [] });
      }),
    );
    const { container } = withClient(<TrustedBy />);

    await waitFor(() => expect(asked).toBe(true));
    expect(container).toBeEmptyDOMElement();
  });
});

describe("The organisation's consent", () => {
  it("is off until an administrator turns it on, then says when and whether it shows", async () => {
    let sent: unknown = null;
    server.use(
      http.get("*/api/v1/settings/showcase", () =>
        HttpResponse.json({
          listed: false,
          consented_at: null,
          shown: false,
          hidden_by_platform: false,
          active: true,
        }),
      ),
      http.put("*/api/v1/settings/showcase", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({
          listed: true,
          consented_at: "2026-10-18T06:00:00Z",
          shown: true,
          hidden_by_platform: false,
          active: true,
        });
      }),
    );
    const user = userEvent.setup();
    withClient(<ShowcaseCard />);

    const toggle = await screen.findByRole("switch", {
      name: "Show our organisation on the ETHR website",
    });
    expect(toggle).not.toBeChecked();

    await user.click(toggle);

    await waitFor(() => expect(sent).toEqual({ listed: true }));
    expect(await screen.findByText(/It is shown now\./)).toBeInTheDocument();
    expect(toast.success).toHaveBeenCalled();
  });
});
