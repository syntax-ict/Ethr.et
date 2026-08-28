import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { BrandingCard } from "@/features/settings/components/branding-card";
import { OrganizationCard } from "@/features/settings/components/organization-card";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

describe("<BrandingCard>", () => {
  it("seeds the fields from the tenant's saved branding", () => {
    renderWithClient(
      <BrandingCard
        logoUrl="https://cdn.example.et/acme.png"
        theme={{ primary_color: "#123456" }}
      />,
    );

    expect(screen.getByLabelText("Logo URL")).toHaveValue(
      "https://cdn.example.et/acme.png",
    );
    expect(screen.getByLabelText("Primary color")).toHaveValue("#123456");
    // Unset colors fall back to the ETHR defaults rather than empty inputs.
    expect(screen.getByLabelText("Accent color")).toHaveValue("#E8A838");
  });

  it("sends the logo and all three colors to PUT /settings/branding", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/branding", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Branding updated" });
      }),
    );

    renderWithClient(<BrandingCard logoUrl={null} theme={null} />);

    fireEvent.change(screen.getByLabelText("Logo URL"), {
      target: { value: "https://cdn.example.et/new.png" },
    });
    fireEvent.change(screen.getByLabelText("Primary color"), {
      target: { value: "#0f4c75" },
    });
    fireEvent.click(screen.getByRole("button", { name: /save branding/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      logo_url: "https://cdn.example.et/new.png",
      primary_color: "#0f4c75",
      secondary_color: "#3282B8",
      accent_color: "#E8A838",
    });
  });

  it("sends null rather than an empty string when the logo is cleared", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/branding", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Branding updated" });
      }),
    );

    renderWithClient(
      <BrandingCard logoUrl="https://cdn.example.et/old.png" theme={null} />,
    );

    fireEvent.change(screen.getByLabelText("Logo URL"), {
      target: { value: "   " },
    });
    fireEvent.click(screen.getByRole("button", { name: /save branding/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.logo_url).toBeNull();
  });

  it("restores the default palette when reset is clicked", () => {
    renderWithClient(
      <BrandingCard
        logoUrl={null}
        theme={{ primary_color: "#ff0000", accent_color: "#00ff00" }}
      />,
    );

    expect(screen.getByLabelText("Primary color")).toHaveValue("#ff0000");

    fireEvent.click(screen.getByRole("button", { name: /reset to defaults/i }));

    expect(screen.getByLabelText("Primary color")).toHaveValue("#0F4C75");
    expect(screen.getByLabelText("Accent color")).toHaveValue("#E8A838");
  });
});

describe("<OrganizationCard>", () => {
  const org = {
    name: "Acme Ethiopia",
    subdomain: "acme",
    type: "private",
    timezone: "Africa/Addis_Ababa",
    locale: "en",
  };

  it("keeps save disabled until something actually changes", () => {
    renderWithClient(<OrganizationCard organization={org} />);

    const save = screen.getByRole("button", { name: /save organization/i });
    expect(save).toBeDisabled();

    fireEvent.change(screen.getByLabelText("Organization Name"), {
      target: { value: "Acme Ethiopia PLC" },
    });

    expect(save).toBeEnabled();
  });

  it("leaves the subdomain read-only — it is the tenant's routing identity", () => {
    renderWithClient(<OrganizationCard organization={org} />);

    expect(screen.getByLabelText("Subdomain")).toBeDisabled();
  });

  it("posts the edited name to PUT /settings/organization", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/organization", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Organization updated" });
      }),
    );

    renderWithClient(<OrganizationCard organization={org} />);

    fireEvent.change(screen.getByLabelText("Organization Name"), {
      target: { value: "Acme Ethiopia PLC" },
    });
    fireEvent.click(screen.getByRole("button", { name: /save organization/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({
      name: "Acme Ethiopia PLC",
      type: "private",
      timezone: "Africa/Addis_Ababa",
      locale: "en",
    });
  });
});
