import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import OrganizationPage from "@/app/(dashboard)/organization/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("next/navigation", () => ({
  usePathname: () => "/organization",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "tenant_admin",
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

const item = (
  code: string,
  name: string,
  name_am: string,
  name_om: string,
  parent_code: string | null = null,
) => ({ code, parent_code, name, name_am, name_om, meta: {} });

const LOOKUPS = {
  version: "abc123abc123",
  items: {
    region: [
      item("addis_ababa", "Addis Ababa", "አዲስ አበባ", "Finfinnee"),
      item("oromia", "Oromia", "ኦሮሚያ", "Oromiyaa"),
      item("amhara", "Amhara", "አማራ", "Amaaraa"),
    ],
    sub_city: [item("arada", "Arada", "አራዳ", "Araadaa", "addis_ababa")],
    town: [
      item("adama", "Adama", "አዳማ", "Adaamaa", "oromia"),
      item("bahir_dar", "Bahir Dar", "ባሕር ዳር", "Baahir Daar", "amhara"),
    ],
    bank: [],
  },
};

const page = (data: unknown[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length },
  links: { first: null, last: null, prev: null, next: null },
});

const LEGACY = {
  public_id: "01HZBRANCH0001",
  name: "Old Branch",
  name_am: null,
  code: "OLD",
  city: "Somewhere unlisted",
  region_code: null,
  town_code: null,
  is_active: true,
};

function serve(rows: unknown[] = []) {
  const bodies: Record<string, unknown>[] = [];
  server.use(
    http.get("*/api/v1/organization/tree", () => HttpResponse.json([])),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(page(rows)),
    ),
    http.get("*/api/v1/lookups", () => HttpResponse.json(LOOKUPS)),
    http.post("*/api/v1/organization/branches", async ({ request }) => {
      bodies.push((await request.json()) as Record<string, unknown>);
      return HttpResponse.json({ ...LEGACY, public_id: "01HZNEW" });
    }),
    http.put("*/api/v1/organization/branches/:id", async ({ request }) => {
      bodies.push((await request.json()) as Record<string, unknown>);
      return HttpResponse.json(LEGACY);
    }),
  );
  return bodies;
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <OrganizationPage />
    </QueryClientProvider>,
  );
}

async function openNewBranch() {
  await userEvent.click(await screen.findByRole("tab", { name: /branches/i }));
  await userEvent.click(await screen.findByRole("button", { name: "Add" }));
  const dialog = await screen.findByRole("dialog", { name: "New Branch" });
  await userEvent.type(within(dialog).getByLabelText(/^Name \*$/), "Piassa");
  return dialog;
}

async function pick(dialog: HTMLElement, field: RegExp, option: string) {
  await userEvent.click(within(dialog).getByRole("combobox", { name: field }));
  await userEvent.click(await screen.findByRole("option", { name: option }));
}

describe("a branch's place", () => {
  it("is a region and a town from the lists, saved with the town's text", async () => {
    const bodies = serve();
    renderPage();
    const dialog = await openNewBranch();

    await pick(dialog, /Region/, "Oromia");
    await pick(dialog, /City/, "Adama");
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({
      region_code: "oromia",
      town_code: "adama",
      city: "Adama",
    });
  });

  it("offers the sub-cities when the region is Addis Ababa", async () => {
    const bodies = serve();
    renderPage();
    const dialog = await openNewBranch();

    await pick(dialog, /Region/, "Addis Ababa");
    await pick(dialog, /City/, "Arada");
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({
      region_code: "addis_ababa",
      town_code: "arada",
      city: "Arada",
    });
  });

  it("drops the town when the region changes, so a town never sits in the wrong region", async () => {
    const bodies = serve();
    renderPage();
    const dialog = await openNewBranch();

    await pick(dialog, /Region/, "Oromia");
    await pick(dialog, /City/, "Adama");
    await pick(dialog, /Region/, "Amhara");
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({
      region_code: "amhara",
      town_code: null,
      city: null,
    });
  });

  it("keeps a city typed before the lists, with no codes", async () => {
    const bodies = serve([LEGACY]);
    renderPage();
    await userEvent.click(
      await screen.findByRole("tab", { name: /branches/i }),
    );
    await screen.findByText("Old Branch");
    await userEvent.click(screen.getByRole("button", { name: /edit/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByLabelText("City")).toHaveValue(
      "Somewhere unlisted",
    );
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({
      city: "Somewhere unlisted",
      region_code: null,
      town_code: null,
    });
  });
});
