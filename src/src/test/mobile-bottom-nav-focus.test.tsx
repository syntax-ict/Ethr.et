import { describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MobileBottomNav } from "@/components/layouts/mobile-bottom-nav";

vi.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
}));

/**
 * `focus-visible:outline-none` with nothing in its place made the phone's tab
 * bar invisible to a keyboard or switch-access user (frontend audit W-01). Every
 * focusable control in the bar that removes the outline must draw a ring.
 */
describe("MobileBottomNav keyboard focus", () => {
  it("never removes the outline without drawing a ring", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const { container } = render(
      <QueryClientProvider client={client}>
        <MobileBottomNav />
      </QueryClientProvider>,
    );

    const controls = Array.from(container.querySelectorAll("a, button"));
    expect(controls.length).toBeGreaterThan(0);
    for (const el of controls) {
      const cls = el.getAttribute("class") ?? "";
      if (cls.includes("focus-visible:outline-none")) {
        expect(cls).toContain("focus-visible:ring");
      }
    }
  });

  it("shows no infinitely pulsing element", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const { container } = render(
      <QueryClientProvider client={client}>
        <MobileBottomNav />
      </QueryClientProvider>,
    );
    expect(container.querySelector(".animate-ping")).toBeNull();
  });
});
