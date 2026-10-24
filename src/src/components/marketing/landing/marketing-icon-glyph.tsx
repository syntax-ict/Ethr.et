import { createElement } from "react";
import { marketingIcon } from "./marketing-icons";

/**
 * The icon a landing-page row names, drawn; nothing when the name is unknown
 * or empty. A component rather than a lookup in the caller's render, so the
 * caller never creates a component type during its own render.
 */
export function MarketingIconGlyph({
  name,
  className,
}: {
  name: string | null | undefined;
  className?: string;
}) {
  const Icon = marketingIcon(name);
  if (!Icon) return null;
  return createElement(Icon, { className, "aria-hidden": true });
}
