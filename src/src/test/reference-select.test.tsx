import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState } from "react";

import { ReferenceSelect } from "@/components/shared/reference-select";
import type {
  Lookups,
  ReferenceKind,
  ReferenceValue,
} from "@/features/reference/api";

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
    sub_city: [],
    town: [
      item("adama", "Adama", "አዳማ", "Adaamaa", "oromia"),
      item("mekelle", "Mekelle", "መቐለ", "Mekelle", "tigray"),
    ],
    bank: [],
  },
};

function Harness({
  kind = "region",
  initial = { code: null, text: "" },
  parentCode,
  allowOther,
}: {
  kind?: ReferenceKind;
  initial?: ReferenceValue;
  parentCode?: string | null;
  allowOther?: boolean;
}) {
  const [value, setValue] = useState<ReferenceValue>(initial);
  return (
    <>
      <ReferenceSelect
        id="place"
        kind={kind}
        value={value}
        onChange={setValue}
        parentCode={parentCode}
        allowOther={allowOther}
      />
      <output data-testid="value">{JSON.stringify(value)}</output>
    </>
  );
}

function renderHarness(props: Parameters<typeof Harness>[0] = {}) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <Harness {...props} />
    </QueryClientProvider>,
  );
}

const value = () => JSON.parse(screen.getByTestId("value").textContent ?? "{}");

beforeEach(() => {
  localStorage.setItem("locale", "en");
  vi.mocked(apiClient.get).mockReset();
  vi.mocked(apiClient.get).mockResolvedValue({ data: LOOKUPS });
});

describe("ReferenceSelect", () => {
  it("offers the list and stores the code with the English name", async () => {
    const user = userEvent.setup();
    renderHarness();

    await user.click(await screen.findByRole("combobox"));
    await user.click(await screen.findByText("Oromia"));

    expect(value()).toEqual({ code: "oromia", text: "Oromia" });
    expect(screen.getByRole("combobox")).toHaveTextContent("Oromia");
  });

  it("finds an entry by its Amharic or Afaan Oromoo spelling", async () => {
    const user = userEvent.setup();
    renderHarness();

    await user.click(await screen.findByRole("combobox"));
    await user.type(screen.getByPlaceholderText("Search..."), "ትግራ");

    expect(await screen.findByText("Tigray")).toBeInTheDocument();
    expect(screen.queryByText("Oromia")).not.toBeInTheDocument();

    await user.clear(screen.getByPlaceholderText("Search..."));
    await user.type(screen.getByPlaceholderText("Search..."), "Finfinnee");

    expect(await screen.findByText("Addis Ababa")).toBeInTheDocument();
  });

  it("narrows towns to the region they belong to", async () => {
    const user = userEvent.setup();
    renderHarness({ kind: "town", parentCode: "oromia" });

    await user.click(await screen.findByRole("combobox"));

    expect(await screen.findByText("Adama")).toBeInTheDocument();
    expect(screen.queryByText("Mekelle")).not.toBeInTheDocument();
  });

  it("lets a name that is not listed be typed, with no code", async () => {
    const user = userEvent.setup();
    renderHarness();

    await user.click(await screen.findByRole("combobox"));
    await user.click(await screen.findByText("Other (type it)"));

    const box = await screen.findByPlaceholderText("Type it here");
    await user.type(box, "Somewhere");

    expect(value()).toEqual({ code: null, text: "Somewhere" });
  });

  it("still offers Other when a search matches nothing", async () => {
    const user = userEvent.setup();
    renderHarness();

    await user.click(await screen.findByRole("combobox"));
    await user.type(screen.getByPlaceholderText("Search..."), "zzzz");

    expect(await screen.findByText("Other (type it)")).toBeInTheDocument();
  });

  it("shows text saved before there was a list as typed, and keeps it", async () => {
    renderHarness({ initial: { code: null, text: "Old Town" } });

    expect(await screen.findByDisplayValue("Old Town")).toBeInTheDocument();
    expect(value()).toEqual({ code: null, text: "Old Town" });
  });

  it("goes back to the list from the text box", async () => {
    const user = userEvent.setup();
    renderHarness({ initial: { code: null, text: "Old Town" } });

    await user.click(
      await screen.findByRole("button", { name: "Choose from the list" }),
    );

    expect(await screen.findByRole("combobox")).toBeInTheDocument();
  });

  it("shows the stored text for a code the list no longer has", async () => {
    renderHarness({ initial: { code: "retired_town", text: "Retired Town" } });

    expect(await screen.findByRole("combobox")).toHaveTextContent(
      "Retired Town",
    );
    expect(value()).toEqual({ code: "retired_town", text: "Retired Town" });
  });

  it("names the saved entry in the reader's language", async () => {
    localStorage.setItem("locale", "am");
    renderHarness({ initial: { code: "oromia", text: "Oromia" } });

    expect(await screen.findByRole("combobox")).toHaveTextContent("ኦሮሚያ");
  });

  it("falls back to a text box when the list cannot be loaded", async () => {
    vi.mocked(apiClient.get).mockRejectedValue(new Error("offline"));
    const user = userEvent.setup();
    renderHarness();

    const box = await screen.findByRole("textbox");
    await user.type(box, "Hawassa");

    await waitFor(() =>
      expect(value()).toEqual({ code: null, text: "Hawassa" }),
    );
  });

  it("does not offer Other where there is nowhere to keep typed text", async () => {
    const user = userEvent.setup();
    renderHarness({ allowOther: false });

    await user.click(await screen.findByRole("combobox"));
    await screen.findByText("Oromia");
    expect(screen.queryByText("Other (type it)")).not.toBeInTheDocument();
  });

  it("locks the box instead of taking text that could not be kept, when the list cannot be loaded", async () => {
    vi.mocked(apiClient.get).mockRejectedValue(new Error("offline"));
    renderHarness({ allowOther: false });

    expect(await screen.findByRole("textbox")).toBeDisabled();
  });
});
