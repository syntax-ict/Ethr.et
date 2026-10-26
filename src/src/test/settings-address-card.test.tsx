import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
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
    subdomains_served: false,
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

let lastPut: unknown = null;
let verifyCalls = 0;

beforeEach(() => {
  lastPut = null;
  verifyCalls = 0;
  server.use(
    http.put("*/api/v1/settings/address", async ({ request }) => {
      lastPut = await request.json();
      return HttpResponse.json({ address: address({ mode: "subdomain" }) });
    }),
    http.post("*/api/v1/settings/address/verify", () => {
      verifyCalls += 1;
      return HttpResponse.json({
        address: address({
          custom_domain: "hr.abay.com",
          custom_domain_status: "verified",
        }),
      });
    }),
  );
});

describe("<AddressCard>", () => {
  it("shows the current address and the three ways to be reached", () => {
    renderCard(address());

    expect(screen.getByTestId("current-address")).toHaveTextContent(
      "https://ethr.et/abay",
    );
    expect(screen.getByRole("radio", { name: "Shared address" })).toBeChecked();
    expect(screen.getByRole("radio", { name: "Own subdomain" })).toBeEnabled();
    expect(screen.getByRole("radio", { name: "Own domain" })).toBeEnabled();
    expect(screen.getByRole("button", { name: /save/i })).toBeDisabled();
  });

  it("says when the subdomain is not served yet, but still lets it be chosen and saved", async () => {
    const user = userEvent.setup();
    renderCard(address({ subdomains_served: false }));

    expect(
      screen.getByText(/not available on this installation yet/i),
    ).toBeInTheDocument();

    await user.click(screen.getByRole("radio", { name: "Own subdomain" }));
    await user.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(lastPut).toEqual({ mode: "subdomain" }));
  });

  it("disables the own-domain option when the plan does not include it", () => {
    renderCard(address({ custom_domain_allowed: false }));

    expect(screen.getByRole("radio", { name: "Own domain" })).toBeDisabled();
    expect(
      screen.getByText(/plan does not include a custom domain/i),
    ).toBeInTheDocument();
  });

  it("assigns a domain in the same save as choosing it", async () => {
    const user = userEvent.setup();
    renderCard(address());

    await user.click(screen.getByRole("radio", { name: "Own domain" }));
    await user.type(screen.getByLabelText("Domain"), "HR.Abay.com");
    await user.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() =>
      expect(lastPut).toEqual({
        mode: "custom_domain",
        custom_domain: "HR.Abay.com",
      }),
    );
  });

  it("shows the server's reason under the domain field", async () => {
    const user = userEvent.setup();
    server.use(
      http.put("*/api/v1/settings/address", () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: {
              custom_domain: ["Your plan does not include a custom domain."],
            },
          },
          { status: 422 },
        ),
      ),
    );
    renderCard(address());

    await user.click(screen.getByRole("radio", { name: "Own domain" }));
    await user.type(screen.getByLabelText("Domain"), "hr.abay.com");
    await user.click(screen.getByRole("button", { name: /save/i }));

    expect(
      await screen.findByText("Your plan does not include a custom domain."),
    ).toBeInTheDocument();
  });

  it("lists the DNS records for a pending domain and verifies on request", async () => {
    const user = userEvent.setup();
    renderCard(
      address({
        mode: "custom_domain",
        custom_domain: "hr.abay.com",
        custom_domain_status: "pending",
        custom_domain_dns: {
          txt_name: "_ethr-verification.hr.abay.com",
          txt_value: "ethr-verification=abc123",
          cname_name: "hr.abay.com",
          cname_target: "ethr.et",
        },
      }),
    );

    expect(
      screen.getByText("_ethr-verification.hr.abay.com"),
    ).toBeInTheDocument();
    expect(screen.getByText("ethr-verification=abc123")).toBeInTheDocument();
    expect(screen.getByText("Pending verification")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /verify domain/i }));

    await waitFor(() => expect(verifyCalls).toBe(1));
  });

  it("names each DNS record the server could not find", async () => {
    const user = userEvent.setup();
    server.use(
      http.post("*/api/v1/settings/address/verify", () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: {
              txt: ["TXT record _ethr-verification.hr.abay.com not found."],
              cname: ["CNAME for hr.abay.com does not point at ethr.et."],
            },
          },
          { status: 422 },
        ),
      ),
    );
    renderCard(
      address({
        mode: "custom_domain",
        custom_domain: "hr.abay.com",
        custom_domain_status: "pending",
        custom_domain_dns: {
          txt_name: "_ethr-verification.hr.abay.com",
          txt_value: "ethr-verification=abc123",
          cname_name: "hr.abay.com",
          cname_target: "ethr.et",
        },
      }),
    );

    await user.click(screen.getByRole("button", { name: /verify domain/i }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("TXT record");
    expect(alert).toHaveTextContent("CNAME for hr.abay.com");
  });
});
