import { describe, it, expect } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { TenantKindCard } from "@/features/admin/components/tenant-kind-card";

function renderCard(props: Parameters<typeof TenantKindCard>[0]) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <TenantKindCard {...props} />
    </QueryClientProvider>,
  );
}

describe("TenantKindCard (the kind in law on the console's organisation page)", () => {
  it("names the kind from the catalogue and carries no control that changes it", async () => {
    renderCard({ kind: "government", needsReview: false, pending: null });

    expect(screen.getByText("Kind of organisation in law")).toBeInTheDocument();
    expect(await screen.findByText("Government office")).toBeInTheDocument();
    expect(screen.queryByText("Needs confirming")).not.toBeInTheDocument();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    expect(
      screen.getByText(
        "The organisation changes this itself, in its Settings.",
      ),
    ).toBeInTheDocument();
  });

  it("flags a kind that still needs confirming", async () => {
    renderCard({ kind: "private", needsReview: true, pending: null });

    expect(await screen.findByText("Private company")).toBeInTheDocument();
    expect(screen.getByText("Needs confirming")).toBeInTheDocument();
  });

  it("states a scheduled change of kind with its date", async () => {
    renderCard({
      kind: "private",
      needsReview: false,
      pending: {
        organization_type: "government",
        effective_from: "2027-02-01",
      },
    });

    const note = await screen.findByRole("status");
    await waitFor(() =>
      expect(note).toHaveTextContent(/Becomes Government office on .*2027/),
    );
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("still shows the stored kind when the catalogue cannot be read", async () => {
    server.use(
      http.get("*/api/v1/organization-types", () =>
        HttpResponse.json({ message: "down" }, { status: 500 }),
      ),
    );
    renderCard({ kind: "government", needsReview: false, pending: null });

    expect(await screen.findByText("government")).toBeInTheDocument();
  });

  it("shows a dash for an organisation with no kind recorded", () => {
    renderCard({ kind: null, needsReview: false, pending: null });

    expect(screen.getByText("—")).toBeInTheDocument();
  });
});
