import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AddressCard } from "@/features/settings/components/address-card";
import type { TenantAddress } from "@/features/settings/api";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like `TenantAddressService::describe`. */
function address(overrides: Partial<TenantAddress> = {}): TenantAddress {
  return {
    mode: "path",
    effective_mode: "path",
    canonical_url: null,
    entry_url: "https://ethr.et/abay",
    subdomain: "abay",
    subdomain_url: "https://abay.ethr.et",
    retired_subdomains: [],
    subdomains_served: true,
    custom_domain: null,
    custom_domain_status: null,
    custom_domain_allowed: true,
    custom_domain_dns: null,
    ...overrides,
  };
}

function renderCard(value: TenantAddress) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<AddressCard address={value} />, { wrapper: Wrapper });
}

const TAKEN = "That name is already in use. Please choose another.";
let puts: unknown[] = [];

beforeEach(() => {
  puts = [];
  localStorage.setItem("tenant", "abay");
  server.use(
    http.get("*/api/v1/settings/address/subdomain/check", ({ request }) => {
      const name = new URL(request.url).searchParams.get("subdomain") ?? "";
      const taken = name === "taken";
      return HttpResponse.json({
        subdomain: name,
        available: !taken,
        message: taken ? TAKEN : null,
      });
    }),
    http.put("*/api/v1/settings/address/subdomain", async ({ request }) => {
      const body = (await request.json()) as { subdomain: string };
      puts.push(body);
      return HttpResponse.json({
        address: address({
          subdomain: body.subdomain,
          entry_url: `https://ethr.et/${body.subdomain}`,
          subdomain_url: `https://${body.subdomain}.ethr.et`,
          retired_subdomains: ["abay"],
        }),
      });
    }),
  );
});

// 2026-10-26: a tenant admin renames the organisation's address. The old name
// keeps working and forwards, and the change is confirmed before it is sent.
describe("renaming the address", () => {
  it("shows the address name and offers to change it", () => {
    renderCard(address());

    expect(screen.getByTestId("address-name")).toHaveTextContent(
      "ethr.et/abay",
    );
    expect(screen.getByRole("button", { name: "Change name" })).toBeEnabled();
  });

  it("checks the name as it is typed, and says why one cannot be used", async () => {
    const user = userEvent.setup();
    renderCard(address());

    await user.click(screen.getByRole("button", { name: "Change name" }));
    const input = screen.getByLabelText("New name");
    const next = screen.getByRole("button", { name: "Continue" });

    await user.clear(input);
    await user.type(input, "ab");
    expect(
      await screen.findByText("Use at least 3 characters."),
    ).toBeInTheDocument();
    expect(next).toBeDisabled();

    await user.clear(input);
    await user.type(input, "taken");
    expect(await screen.findByText(TAKEN)).toBeInTheDocument();
    expect(next).toBeDisabled();

    await user.clear(input);
    await user.type(input, "Abay Bank");
    // Spaces go and capitals are lowered as it is typed.
    expect(input).toHaveValue("abaybank");
    expect(await screen.findByText("Available")).toBeInTheDocument();
    expect(next).toBeEnabled();
  });

  it("says what changes before renaming, then sends the new name and remembers it", async () => {
    const user = userEvent.setup();
    renderCard(address());

    await user.click(screen.getByRole("button", { name: "Change name" }));
    const input = screen.getByLabelText("New name");
    await user.clear(input);
    await user.type(input, "abay-bank");
    await screen.findByText("Available");
    await user.click(screen.getByRole("button", { name: "Continue" }));

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent(
      "Change your address to ethr.et/abay-bank?",
    );
    expect(dialog).toHaveTextContent(
      "ethr.et/abay keeps working and forwards to ethr.et/abay-bank.",
    );
    expect(dialog).toHaveTextContent(
      "Your subdomain becomes abay-bank.ethr.et.",
    );
    expect(puts).toEqual([]);

    await user.click(
      within(dialog).getByRole("button", { name: "Change address" }),
    );

    await vi.waitFor(() => expect(puts).toEqual([{ subdomain: "abay-bank" }]));
    await vi.waitFor(() =>
      expect(localStorage.getItem("tenant")).toBe("abay-bank"),
    );
  });

  it("lists the earlier names that still forward", () => {
    renderCard(address({ retired_subdomains: ["abay-old", "abay-2019"] }));

    expect(screen.getByTestId("address-retired")).toHaveTextContent(
      "Earlier names that still forward here: abay-old, abay-2019",
    );
  });
});
