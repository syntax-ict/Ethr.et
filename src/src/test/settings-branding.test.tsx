import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { BrandingCard } from "@/features/settings/components/branding-card";
import { OrganizationCard } from "@/features/settings/components/organization-card";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>{ui}</CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<BrandingCard>", () => {
  it("seeds the fields from the tenant's saved branding", () => {
    renderWithClient(
      <BrandingCard
        uploadedLogoUrl="/api/v1/organisation-logos/T/abc"
        theme={{ primary_color: "#123456" }}
      />,
    );

    expect(
      screen.getByText("Your uploaded logo is in use"),
    ).toBeInTheDocument();
    expect(screen.getByLabelText("Primary colour")).toHaveValue("#123456");
    // Unset colors fall back to the ETHR defaults rather than empty inputs.
    expect(screen.getByLabelText("Accent colour")).toHaveValue("#E8A838");
  });

  it("sends the three colors, and no logo link, to PUT /settings/branding", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/branding", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Branding updated" });
      }),
    );

    renderWithClient(<BrandingCard theme={null} />);

    // The typed logo link was retired on 2026-10-15: images are uploads.
    expect(screen.queryByLabelText(/link to a logo/i)).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Primary colour"), {
      target: { value: "#123456" },
    });
    fireEvent.click(screen.getByRole("button", { name: /save branding/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({
      primary_color: "#123456",
      secondary_color: "#3282B8",
      accent_color: "#E8A838",
    });
  });

  it("restores the default palette when reset is clicked", () => {
    renderWithClient(
      <BrandingCard
        theme={{ primary_color: "#ff0000", accent_color: "#00ff00" }}
      />,
    );

    expect(screen.getByLabelText("Primary colour")).toHaveValue("#ff0000");

    fireEvent.click(screen.getByRole("button", { name: /reset to defaults/i }));

    expect(screen.getByLabelText("Primary colour")).toHaveValue("#0F4C75");
    expect(screen.getByLabelText("Accent colour")).toHaveValue("#E8A838");
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

    const save = screen.getByRole("button", { name: /save organisation/i });
    expect(save).toBeDisabled();

    fireEvent.change(screen.getByLabelText("Organisation name"), {
      target: { value: "Acme Ethiopia PLC" },
    });

    expect(save).toBeEnabled();
  });

  it("leaves the subdomain read-only — it is the tenant's routing identity", () => {
    renderWithClient(<OrganizationCard organization={org} />);

    expect(screen.getByLabelText("Address name")).toHaveAttribute("readonly");
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

    fireEvent.change(screen.getByLabelText("Organisation name"), {
      target: { value: "Acme Ethiopia PLC" },
    });
    fireEvent.click(screen.getByRole("button", { name: /save organisation/i }));

    await waitFor(() => expect(body).not.toBeNull());
    // The kind in force is not re-sent: on the server, choosing it again
    // would have said nothing, and once it withdrew a scheduled change (K-57).
    // Nor is the industry, unless it changed: the server refuses a changed
    // one it does not know, and a legacy value must save as it is.
    expect(body).toEqual({
      name: "Acme Ethiopia PLC",
      timezone: "Africa/Addis_Ababa",
      locale: "en",
    });
  });

  it("sends the industry when it was changed", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/organization", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Organization updated" });
      }),
    );
    const user = userEvent.setup();

    renderWithClient(
      <OrganizationCard organization={{ ...org, type: "bank" }} />,
    );

    await user.click(screen.getByRole("combobox", { name: /industry/i }));
    await user.click(
      await screen.findByRole("option", { name: "Hospital / Health" }),
    );
    fireEvent.click(screen.getByRole("button", { name: /save organisation/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ type: "hospital" });
  });

  it("offers the kinds of organisation from the catalogue, in the reader's language", async () => {
    server.use(
      http.get("*/organization-types", () =>
        HttpResponse.json({
          data: [
            {
              code: "private",
              name: "Private organisation",
              name_am: "የግል ድርጅት",
              description: null,
            },
            {
              code: "government",
              name: "Government office (civil service)",
              name_am: "የመንግሥት መሥሪያ ቤት",
              description: null,
            },
          ],
        }),
      ),
    );

    renderWithClient(<OrganizationCard organization={org} />);

    expect(
      screen.getByLabelText("Kind of organisation in law"),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(
        screen.getByLabelText("Kind of organisation in law"),
      ).toHaveTextContent("Private organisation"),
    );
  });

  it("asks an organisation whose kind was inferred to confirm it, and the confirmation saves it", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/organization", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Organization updated" });
      }),
    );

    renderWithClient(
      <OrganizationCard
        organization={{
          ...org,
          organization_type: "government",
          organization_type_needs_review: true,
        }}
      />,
    );

    expect(screen.getByRole("status")).toHaveTextContent(
      /confirm the kind of organisation/i,
    );
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ organization_type: "government" });
  });

  it("sends no start date while the kind is unchanged, even after payroll was approved", async () => {
    localStorage.setItem("ethr.calendar", "gregorian");
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/organization", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Organization updated" });
      }),
    );

    renderWithClient(
      <OrganizationCard
        organization={{
          ...org,
          organization_type: "private",
          organization_type_needs_review: false,
          organization_type_earliest_change: "2026-12-01",
        }}
      />,
    );

    // Unchanged kind: no date to ask for.
    expect(screen.queryByLabelText("Applies from")).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Organisation name"), {
      target: { value: "Acme Ethiopia PLC" },
    });
    fireEvent.click(screen.getByRole("button", { name: /save organisation/i }));
    await waitFor(() => expect(body).not.toBeNull());
    expect(body).not.toHaveProperty("effective_from");
    localStorage.removeItem("ethr.calendar");
  });

  it("says when a scheduled change of kind takes effect", () => {
    localStorage.setItem("ethr.calendar", "gregorian");
    renderWithClient(
      <OrganizationCard
        organization={{
          ...org,
          organization_type: "private",
          organization_type_needs_review: false,
          organization_type_pending: {
            organization_type: "government",
            effective_from: "2027-01-01",
          },
          organization_type_earliest_change: "2026-12-01",
        }}
      />,
    );

    expect(screen.getByRole("status")).toHaveTextContent(
      /becomes government on .*payroll before then keeps the current kind/i,
    );
    localStorage.removeItem("ethr.calendar");
  });

  it("withdraws a scheduled change of kind only from its own button", async () => {
    localStorage.setItem("ethr.calendar", "gregorian");
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/organization", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Organization updated" });
      }),
    );

    renderWithClient(
      <OrganizationCard
        organization={{
          ...org,
          organization_type: "private",
          organization_type_needs_review: false,
          organization_type_pending: {
            organization_type: "government",
            effective_from: "2027-01-01",
          },
          organization_type_earliest_change: "2026-12-01",
        }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Cancel this change" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({ cancel_pending_organization_type: true });
    localStorage.removeItem("ethr.calendar");
  });

  it("shows no confirmation once the kind is confirmed", () => {
    renderWithClient(
      <OrganizationCard
        organization={{
          ...org,
          organization_type: "private",
          organization_type_needs_review: false,
        }}
      />,
    );

    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});
