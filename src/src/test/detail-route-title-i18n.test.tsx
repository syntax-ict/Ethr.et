import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import am from "@/lib/i18n/locales/am.json";
import { PageTitleBar } from "@/components/layouts/page-title-bar";
import { useDocumentTitle } from "@/lib/hooks/useDocumentTitle";

// A detail page (/employees/<ULID>) borrows its parent's route metadata, and its
// heading, breadcrumb and browser tab must be translated like the parent's. They
// were not: the translation key was built from the URL, `route.employees.<ULID>`,
// which exists in no dictionary, so an Amharic user got the English fallback on
// every record page (live test against the local production rehearsal,
// 2026-10-06), while the list page above it was in Amharic.

const path = vi.hoisted(() => ({
  value: "/employees/01M496SQ1JPXTTW187E0Y0TTZW",
}));

vi.mock("next/navigation", () => ({
  usePathname: () => path.value,
}));

const dict = am as Record<string, string>;

function TitleProbe() {
  useDocumentTitle();
  return null;
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "am");
  path.value = "/employees/01M496SQ1JPXTTW187E0Y0TTZW";
});

describe("detail pages are translated like the section they belong to", () => {
  it("translates the list page (control: the harness itself translates)", async () => {
    path.value = "/employees";
    render(<PageTitleBar />);

    expect(
      await screen.findByRole("heading", {
        level: 1,
        name: dict["route.employees.label"],
      }),
    ).toBeInTheDocument();
  });

  it("heads an employee record with the Amharic section name", async () => {
    render(<PageTitleBar />);

    expect(
      await screen.findByRole("heading", {
        level: 1,
        name: dict["route.employees.label"],
      }),
    ).toBeInTheDocument();
    expect(screen.getByText(dict["route.employees.desc"])).toBeInTheDocument();
    expect(
      screen.queryByText("Manage your organization's workforce"),
    ).toBeNull();
  });

  it("titles the browser tab of a payroll run in Amharic", async () => {
    path.value = "/payroll/01M496VPDBNR3N3SW52E5SK3C7";
    render(<TitleProbe />);

    await waitFor(() =>
      expect(document.title.startsWith(dict["route.payroll.label"])).toBe(true),
    );
  });
});
