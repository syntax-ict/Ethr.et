/**
 * The questions the FAQ page asks, in order.
 *
 * Extracted from `faq-content.tsx` so the page and its `FAQPage` structured data
 * read from one list. Google's own guidance is that JSON-LD must describe what
 * the page actually shows, and a second hand-written copy of these keys is
 * exactly how that stops being true — someone adds a question to the visible
 * list and the rich result keeps advertising the old one.
 *
 * Each entry names a translation key pair: `marketing.faq_page.<item>_q` and
 * `_a`.
 */
export const FAQ_CATEGORIES = [
  {
    key: "general",
    items: ["what_is", "who_for", "languages", "calendar"],
  },
  {
    key: "billing",
    items: ["trial", "after_trial", "change_plan"],
  },
  {
    key: "features",
    items: ["attendance_methods", "offline", "payroll"],
  },
  {
    key: "security",
    items: ["security", "hosting"],
  },
] as const;

export const FAQ_ITEMS: readonly string[] = FAQ_CATEGORIES.flatMap(
  (category) => [...category.items],
);
