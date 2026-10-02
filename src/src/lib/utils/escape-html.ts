const ENTITIES: Record<string, string> = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#39;",
};

/**
 * Escape a string for interpolation into an HTML document built by hand.
 *
 * Only for the rare place that assembles markup as a string (the printable
 * payslip window). Everywhere React renders, it already escapes.
 */
export function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (ch) => ENTITIES[ch]);
}
