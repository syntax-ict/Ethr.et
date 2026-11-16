import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/**
 * A menu taller than the room Radix has for it used to be clipped by the
 * viewport, hiding its first item. It must cap its height and scroll instead.
 */
describe("DropdownMenuContent height", () => {
  it("is capped to the available height and scrolls rather than clips", () => {
    render(
      <DropdownMenu defaultOpen>
        <DropdownMenuTrigger>Open</DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem>First</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>,
    );

    const content = screen.getByRole("menu");
    expect(content.className).toContain(
      "max-h-[var(--radix-dropdown-menu-content-available-height)]",
    );
    expect(content.className).toContain("overflow-y-auto");
    expect(content.className).not.toContain("overflow-hidden");
  });
});
