import { describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import type { components } from "@/api/generated";
import DirectoryPage from "@/app/(dashboard)/directory/page";

type DirectoryResource = components["schemas"]["DirectoryResource"];

function person(n: number): DirectoryResource {
  return {
    public_id: `01HZPERSON${String(n).padStart(16, "0")}`,
    name: `Colleague ${n}`,
    phone: null,
    email: `colleague${n}@example.com`,
    photo_url: null,
    photo_thumb_url: null,
    department: "Engineering",
    position: "Developer",
    branch: "Headquarters",
  };
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <DirectoryPage />
    </QueryClientProvider>,
  );
}

describe("Staff directory", () => {
  it("reaches colleagues past the first page", async () => {
    const pagesAsked: string[] = [];
    server.use(
      http.get("*/api/v1/directory", ({ request }) => {
        const page = new URL(request.url).searchParams.get("page") ?? "1";
        pagesAsked.push(page);
        return HttpResponse.json({
          data: [person(page === "1" ? 1 : 51)],
          links: { first: null, last: null, prev: null, next: null },
          meta: {
            current_page: Number(page),
            last_page: 2,
            per_page: 50,
            total: 51,
            from: page === "1" ? 1 : 51,
            to: page === "1" ? 50 : 51,
          },
        });
      }),
    );

    renderPage();
    expect(await screen.findByText("Colleague 1")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /Next/ }));

    expect(await screen.findByText("Colleague 51")).toBeInTheDocument();
    expect(pagesAsked).toContain("2");

    // And it stays there. SearchInput's debounce fired unchanged text on every
    // render, the page reset to 1 on a search, and page 2 lasted 300 ms. Fast
    // runs found "Colleague 51" inside that window; loaded ones did not
    // (audit N92).
    await new Promise((resolve) => setTimeout(resolve, 500));
    expect(screen.getByText("Colleague 51")).toBeInTheDocument();
  });
});
