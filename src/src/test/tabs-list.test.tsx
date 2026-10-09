import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";

/**
 * The employee page's twelve tabs sat on one fixed-height line, 1163px wide:
 * on a phone the whole page scrolled sideways by 779px (audit N36). Measured
 * in the browser; jsdom has no layout, so this pins the classes that decide it.
 */
describe("<TabsList>", () => {
  function renderTabs() {
    render(
      <Tabs defaultValue="a">
        <TabsList>
          <TabsTrigger value="a">Information</TabsTrigger>
          <TabsTrigger value="b">Employment</TabsTrigger>
        </TabsList>
      </Tabs>,
    );
    return screen.getByRole("tablist");
  }

  it("wraps tabs that do not fit instead of widening the page", () => {
    const list = renderTabs();
    expect(list).toHaveClass("flex-wrap", "max-w-full");
  });

  it("grows to hold a wrapped row rather than spilling out of a fixed height", () => {
    const list = renderTabs();
    expect(list).toHaveClass("min-h-10");
    expect(list).not.toHaveClass("h-10");
  });
});
