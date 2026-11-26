import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState } from "react";

import {
  AddressForm,
  EMPTY_ADDRESS,
  addressToPayload,
  useFormatAddress,
  type AddressFields,
} from "@/components/shared/address-fields";
import type { Lookups } from "@/features/reference/api";

vi.mock("@/api/client", () => ({ apiClient: { get: vi.fn() } }));

import { apiClient } from "@/api/client";

const item = (
  code: string,
  name: string,
  name_am: string,
  name_om: string,
  parent_code: string | null = null,
) => ({ code, parent_code, name, name_am, name_om, meta: {} });

const LOOKUPS: Lookups = {
  version: "abc123abc123",
  items: {
    region: [
      item("addis_ababa", "Addis Ababa", "አዲስ አበባ", "Finfinnee"),
      item("oromia", "Oromia", "ኦሮሚያ", "Oromiyaa"),
      item("tigray", "Tigray", "ትግራይ", "Tigraay"),
    ],
    sub_city: [
      item("bole", "Bole", "ቦሌ", "Boolee", "addis_ababa"),
      item("arada", "Arada", "አራዳ", "Araddaa", "addis_ababa"),
    ],
    town: [
      item("adama", "Adama", "አዳማ", "Adaamaa", "oromia"),
      item("mekelle", "Mekelle", "መቐለ", "Mekelle", "tigray"),
    ],
    bank: [],
  },
};

function Harness({ initial = EMPTY_ADDRESS }: { initial?: AddressFields }) {
  const [value, setValue] = useState<AddressFields>(initial);
  return (
    <>
      <AddressForm value={value} onChange={setValue} />
      <output data-testid="payload">
        {JSON.stringify(addressToPayload(value))}
      </output>
    </>
  );
}

function renderWith(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

const payload = () =>
  JSON.parse(screen.getByTestId("payload").textContent ?? "{}");

beforeEach(() => {
  localStorage.setItem("locale", "en");
  vi.mocked(apiClient.get).mockReset();
  vi.mocked(apiClient.get).mockResolvedValue({ data: LOOKUPS });
});

describe("addressToPayload", () => {
  it("sends empty boxes as null, so a cleared box clears the column", () => {
    expect(addressToPayload(EMPTY_ADDRESS)).toEqual({
      address_region_code: null,
      address_region_text: null,
      address_sub_city_code: null,
      address_zone_or_sub_city_text: null,
      address_town_code: null,
      address_town_text: null,
      address_woreda: null,
      address_kebele: null,
      address_house_number: null,
    });
  });

  it("never sends a code without its text", () => {
    const out = addressToPayload({
      ...EMPTY_ADDRESS,
      address_town_code: "adama",
      address_town_text: "  ",
    });
    expect(out.address_town_code).toBeNull();
    expect(out.address_town_text).toBeNull();
  });
});

describe("AddressForm", () => {
  it("picks a region, then a town of that region only", async () => {
    const user = userEvent.setup();
    renderWith(<Harness />);

    await user.click(await screen.findByRole("combobox", { name: "Region" }));
    await user.click(await screen.findByText("Oromia"));
    await user.click(
      await screen.findByRole("combobox", { name: "Town or city" }),
    );

    expect(await screen.findByText("Adama")).toBeInTheDocument();
    expect(screen.queryByText("Mekelle")).not.toBeInTheDocument();

    await user.click(screen.getByText("Adama"));

    expect(payload()).toMatchObject({
      address_region_code: "oromia",
      address_region_text: "Oromia",
      address_town_code: "adama",
      address_town_text: "Adama",
    });
  });

  it("asks for a sub-city, not a zone and town, in Addis Ababa", async () => {
    const user = userEvent.setup();
    renderWith(<Harness />);

    await user.click(await screen.findByRole("combobox", { name: "Region" }));
    await user.click(await screen.findByText("Addis Ababa"));

    expect(
      await screen.findByRole("combobox", { name: "Sub-city" }),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText("Zone")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Town or city")).not.toBeInTheDocument();

    await user.click(screen.getByRole("combobox", { name: "Sub-city" }));
    await user.click(await screen.findByText("Bole"));

    expect(payload()).toMatchObject({
      address_region_code: "addis_ababa",
      address_sub_city_code: "bole",
      address_zone_or_sub_city_text: "Bole",
    });
  });

  it("clears the town when the region changes", async () => {
    const user = userEvent.setup();
    renderWith(
      <Harness
        initial={{
          ...EMPTY_ADDRESS,
          address_region_code: "oromia",
          address_region_text: "Oromia",
          address_town_code: "adama",
          address_town_text: "Adama",
        }}
      />,
    );

    await user.click(await screen.findByRole("combobox", { name: "Region" }));
    await user.click(await screen.findByText("Tigray"));

    expect(payload()).toMatchObject({
      address_region_code: "tigray",
      address_town_code: null,
      address_town_text: null,
    });
  });

  it("can clear a chosen region", async () => {
    const user = userEvent.setup();
    renderWith(
      <Harness
        initial={{
          ...EMPTY_ADDRESS,
          address_region_code: "oromia",
          address_region_text: "Oromia",
        }}
      />,
    );

    await user.click(await screen.findByRole("combobox", { name: "Region" }));
    await user.click(await screen.findByText("Clear"));

    expect(payload()).toMatchObject({
      address_region_code: null,
      address_region_text: null,
    });
  });

  it("keeps typed zone and the woreda, kebele and house number as typed", async () => {
    const user = userEvent.setup();
    renderWith(<Harness />);

    await user.type(await screen.findByLabelText("Zone"), "East Shewa");
    await user.type(screen.getByLabelText("Woreda"), "04");
    await user.type(screen.getByLabelText("Kebele"), "09");
    await user.type(screen.getByLabelText("House number"), "123");

    expect(payload()).toMatchObject({
      address_sub_city_code: null,
      address_zone_or_sub_city_text: "East Shewa",
      address_woreda: "04",
      address_kebele: "09",
      address_house_number: "123",
    });
  });

  it("types the town when there is no region to narrow it", async () => {
    const user = userEvent.setup();
    renderWith(<Harness />);

    await user.type(await screen.findByLabelText("Town or city"), "A village");

    expect(payload()).toMatchObject({
      address_town_code: null,
      address_town_text: "A village",
    });
  });
});

function Shown({ record }: { record: Partial<AddressFields> }) {
  const format = useFormatAddress();
  return <p data-testid="line">{format(record)}</p>;
}

describe("useFormatAddress", () => {
  it("reads a picked place in the reader's language", async () => {
    localStorage.setItem("locale", "am");
    renderWith(
      <Shown
        record={{
          address_region_code: "oromia",
          address_region_text: "Oromia",
          address_town_code: "adama",
          address_town_text: "Adama",
          address_kebele: "09",
        }}
      />,
    );

    await waitFor(() =>
      expect(screen.getByTestId("line").textContent).toContain("አዳማ"),
    );
    expect(screen.getByTestId("line").textContent).toContain("ኦሮሚያ");
  });

  it("shows typed text as saved, and nothing for no address", async () => {
    renderWith(
      <>
        <Shown record={{ address_town_text: "A village" }} />
      </>,
    );

    await waitFor(() =>
      expect(screen.getByTestId("line").textContent).toBe("A village"),
    );
  });
});
