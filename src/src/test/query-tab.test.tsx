import { describe, it, expect, afterEach } from "vitest";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useQueryTab } from "@/lib/hooks/useQueryTab";

/**
 * `?tab=` was read but never written, so a link to Security or SSO could not
 * be shared and a reload dropped the tab (SETTINGS-REVIEW-2026-11-22, F8).
 */
const TABS = ["general", "branding", "security"] as const;

function Harness() {
  const [tab, setTab] = useQueryTab(TABS, "general");
  return (
    <>
      <output aria-label="tab">{tab}</output>
      {TABS.map((t) => (
        <button key={t} onClick={() => setTab(t)}>
          {t}
        </button>
      ))}
      <button onClick={() => setTab("nonsense")}>nonsense</button>
    </>
  );
}

function at(url: string) {
  window.history.replaceState(null, "", url);
}

afterEach(() => at("/"));

describe("useQueryTab", () => {
  it("opens the tab a link carries", () => {
    at("/settings?tab=security");
    render(<Harness />);
    expect(screen.getByLabelText("tab")).toHaveTextContent("security");
  });

  it("opens the default when there is no ?tab=", () => {
    at("/settings");
    render(<Harness />);
    expect(screen.getByLabelText("tab")).toHaveTextContent("general");
  });

  it("falls back to the default for an unknown ?tab=", () => {
    at("/settings?tab=nonsense");
    render(<Harness />);
    expect(screen.getByLabelText("tab")).toHaveTextContent("general");
  });

  it("writes the chosen tab to the address", async () => {
    at("/settings");
    render(<Harness />);
    await userEvent.click(screen.getByRole("button", { name: "branding" }));
    expect(screen.getByLabelText("tab")).toHaveTextContent("branding");
    expect(window.location.search).toBe("?tab=branding");
    expect(window.location.pathname).toBe("/settings");
  });

  it("leaves the default tab out of the address", async () => {
    at("/settings?tab=security");
    render(<Harness />);
    await userEvent.click(screen.getByRole("button", { name: "general" }));
    expect(screen.getByLabelText("tab")).toHaveTextContent("general");
    expect(window.location.search).toBe("");
    expect(window.location.href).not.toContain("tab=");
  });

  it("cleans an unknown ?tab= once a tab is chosen", async () => {
    at("/settings?tab=nonsense");
    render(<Harness />);
    await userEvent.click(screen.getByRole("button", { name: "general" }));
    expect(window.location.search).toBe("");
  });

  it("keeps other parameters and the hash", async () => {
    at("/settings?org=abay&tab=branding#logo");
    render(<Harness />);
    await userEvent.click(screen.getByRole("button", { name: "security" }));
    const params = new URLSearchParams(window.location.search);
    expect(params.get("tab")).toBe("security");
    expect(params.get("org")).toBe("abay");
    expect(window.location.hash).toBe("#logo");

    await userEvent.click(screen.getByRole("button", { name: "general" }));
    expect(window.location.search).toBe("?org=abay");
    expect(window.location.hash).toBe("#logo");
  });

  it("ignores a tab that is not allowed", async () => {
    at("/settings?tab=branding");
    render(<Harness />);
    await userEvent.click(screen.getByRole("button", { name: "nonsense" }));
    expect(screen.getByLabelText("tab")).toHaveTextContent("branding");
    expect(window.location.search).toBe("?tab=branding");
  });

  it("replaces the history entry, so Back leaves in one step", async () => {
    at("/settings");
    const before = window.history.length;
    render(<Harness />);
    await userEvent.click(screen.getByRole("button", { name: "branding" }));
    await userEvent.click(screen.getByRole("button", { name: "security" }));
    expect(window.history.length).toBe(before);
  });

  it("follows Back/Forward to an entry with another ?tab=", () => {
    at("/settings?tab=security");
    render(<Harness />);
    act(() => {
      at("/settings");
      window.dispatchEvent(new PopStateEvent("popstate"));
    });
    expect(screen.getByLabelText("tab")).toHaveTextContent("general");
  });
});
