import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import VoicePage from "@/app/(dashboard)/voice/page";

/**
 * Employee voice, from the employee's side: the pulse sends only a score,
 * topics and a comment; an anonymous concern hands back a code that is shown
 * once; and the page says plainly what is and is not stored.
 */
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ can: { manageVoice: false } }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <VoicePage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
  server.use(
    http.get("*/voice/pulse", () =>
      HttpResponse.json({ week: "2026-W42", answered: false, tags: [] }),
    ),
    http.get("*/voice/concerns/mine", () => HttpResponse.json({ data: [] })),
    http.get("*/voice/recognitions", () =>
      HttpResponse.json({
        data: [],
        meta: { current_page: 1, last_page: 1, total: 0 },
      }),
    ),
  );
});

describe("Voice page", () => {
  it("sends the weekly pulse with a score, topics and a comment - nothing else", async () => {
    let body: unknown = null;
    server.use(
      http.post("*/voice/pulse", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json(
          { week: "2026-W42", answered: true },
          { status: 201 },
        );
      }),
    );

    renderPage();

    expect(await screen.findByText(/anonymous by design/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("radio", { name: "Good" }));
    fireEvent.click(screen.getByRole("button", { name: "Workload" }));
    fireEvent.change(screen.getByLabelText(/anything you want hr to know/i), {
      target: { value: "Long shifts" },
    });
    fireEvent.click(screen.getByRole("button", { name: /send anonymously/i }));

    await waitFor(() =>
      expect(body).toEqual({
        score: 4,
        tags: ["workload"],
        comment: "Long shifts",
      }),
    );
  });

  it("shows an anonymous reporter their code, once", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post("*/voice/concerns", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          {
            public_id: "c1",
            category: "harassment",
            subject: "x",
            description: "y",
            status: "new",
            anonymous: true,
            tracking_code: "ABCD-EFGH-JKLM-NPQR",
          },
          { status: 201 },
        );
      }),
    );

    renderPage();
    fireEvent.mouseDown(await screen.findByRole("tab", { name: /speak up/i }));
    fireEvent.click(screen.getByRole("tab", { name: /speak up/i }));

    fireEvent.change(await screen.findByLabelText(/subject/i), {
      target: { value: "Remarks on the night shift" },
    });
    fireEvent.change(screen.getByLabelText(/what happened/i), {
      target: { value: "A supervisor keeps making remarks." },
    });
    // Anonymous is the default.
    expect(
      screen.getByRole("switch", { name: /send anonymously/i }),
    ).toBeChecked();
    fireEvent.click(screen.getByRole("button", { name: /send to hr/i }));

    expect(await screen.findByTestId("tracking-code")).toHaveTextContent(
      "ABCD-EFGH-JKLM-NPQR",
    );
    expect(body).toMatchObject({ anonymous: true, category: "safety" });
  });
});
