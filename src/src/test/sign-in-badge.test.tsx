import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { SignInBadge } from "@/features/employees/components/sign-in-badge";

describe("<SignInBadge>", () => {
  it("says so for someone with no login", () => {
    render(<SignInBadge state="none" status="confirmed" />);
    expect(screen.getByText("No sign-in")).toBeInTheDocument();
  });

  it("says so for someone invited who has not finished", () => {
    render(<SignInBadge state="invited" status="probation" />);
    expect(screen.getByText("Invited, not activated")).toBeInTheDocument();
  });

  it.each(["active", "inactive", "suspended", undefined, null] as const)(
    "shows nothing for %s",
    (state) => {
      const { container } = render(
        <SignInBadge state={state} status="confirmed" />,
      );
      expect(container).toBeEmptyDOMElement();
    },
  );

  it.each(["resigned", "terminated", "retired", "suspended", null])(
    "shows nothing for someone whose status is %s, whatever their sign-in",
    (status) => {
      const { container } = render(
        <SignInBadge state="none" status={status} />,
      );
      expect(container).toBeEmptyDOMElement();
    },
  );
});
