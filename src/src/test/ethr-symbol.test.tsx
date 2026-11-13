import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { EthrLogoTile, EthrWordmark } from "@/components/shared/ethr-logo";

describe("EthrLogoTile", () => {
  it("draws the ETHR mark, not a letter, when there is no operator logo", () => {
    const { container } = render(<EthrLogoTile className="h-8 w-8" />);

    expect(container.querySelector("svg")).not.toBeNull();
    expect(container.querySelector("svg circle")).not.toBeNull();
    expect(container.textContent).not.toContain("E");
  });

  it("uses the heavier small variant when asked", () => {
    const { container } = render(<EthrLogoTile small />);

    expect(container.querySelector("svg circle")?.getAttribute("r")).toBe("5");
  });

  it("still shows the operator logo when there is one", () => {
    const { container } = render(
      <EthrLogoTile logoUrl="https://example.test/logo.png" />,
    );

    expect(container.querySelector("img")).not.toBeNull();
    expect(container.querySelector("svg")).toBeNull();
  });

  it("names the drawn wordmark for assistive technology", () => {
    const { getByRole } = render(<EthrWordmark />);

    expect(getByRole("img", { name: "ETHR" })).not.toBeNull();
  });
});
