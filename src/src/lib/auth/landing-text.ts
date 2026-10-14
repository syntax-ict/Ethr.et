import type {
  PortalSection,
  TenantLanding,
} from "@/lib/auth/use-auth-host-context";

/**
 * Reading an organisation's portal content: which language's text to show,
 * which sections are on and in what order. The server already filtered the
 * values (`TenantLanding::present`); this only chooses among them.
 */

export const PORTAL_SECTIONS: readonly PortalSection[] = [
  "about",
  "announcements",
  "vacancies",
  "holidays",
  "contact",
];

/** Holidays are off until chosen; the rest draw nothing until written. */
const SECTION_DEFAULTS: Record<PortalSection, boolean> = {
  about: true,
  announcements: true,
  vacancies: true,
  holidays: false,
  contact: true,
};

/** The curated lists the about section's facts come from (server-checked). */
export const SECTORS = [
  "agriculture",
  "construction",
  "education",
  "finance",
  "government",
  "health",
  "hospitality",
  "logistics",
  "manufacturing",
  "media",
  "mining",
  "ngo",
  "retail",
  "services",
  "technology",
  "telecom",
  "utilities",
  "other",
] as const;

export const STAFF_BANDS = [
  "1-10",
  "11-50",
  "51-200",
  "201-500",
  "501-1000",
  "1000+",
] as const;

export const LINKS = [
  "website",
  "linkedin",
  "facebook",
  "x",
  "telegram",
  "youtube",
  "privacy",
  "terms",
] as const;

type BilingualKey = "headline" | "message" | "about" | "address" | "hours";

/**
 * The text in the viewer's language, else the other language's: an
 * organisation that wrote only English is still readable in Amharic, and the
 * reverse.
 */
export function localised(
  landing: TenantLanding | null | undefined,
  key: BilingualKey,
  locale: string,
): string | null {
  if (!landing) return null;
  const en = landing[key] ?? null;
  const am = landing[`${key}_am`] ?? null;
  return locale === "am" ? am || en || null : en || am || null;
}

export function sectionOn(
  landing:
    { sections?: Partial<Record<string, boolean>> | null } | null | undefined,
  section: PortalSection,
): boolean {
  const stored = landing?.sections?.[section];
  return typeof stored === "boolean" ? stored : SECTION_DEFAULTS[section];
}

/** The stored order with anything missing appended in the default order. */
export function sectionOrder(
  landing: { order?: readonly string[] | null } | null | undefined,
): PortalSection[] {
  const order: PortalSection[] = [];
  for (const section of (landing?.order ?? []) as PortalSection[]) {
    if (PORTAL_SECTIONS.includes(section) && !order.includes(section)) {
      order.push(section);
    }
  }
  for (const section of PORTAL_SECTIONS) {
    if (!order.includes(section)) order.push(section);
  }
  return order;
}

/** A link to the place on a map, in the viewer's own maps application. */
export function mapsUrl(latitude: number, longitude: number): string {
  return `https://www.google.com/maps/search/?api=1&query=${latitude},${longitude}`;
}

/** Whether the about section has anything to draw. */
export function hasAbout(
  landing: TenantLanding | null | undefined,
  branchCount: number | null | undefined,
): boolean {
  return Boolean(
    landing &&
    (landing.about ||
      landing.about_am ||
      landing.founded_year ||
      landing.sector ||
      landing.staff_band ||
      (landing.show_branch_count && branchCount)),
  );
}

/** Whether the contact section has anything to draw. */
export function hasContact(
  landing: TenantLanding | null | undefined,
  branches: number,
): boolean {
  return Boolean(
    landing &&
    (landing.address ||
      landing.address_am ||
      landing.hours ||
      landing.hours_am ||
      landing.support_phone ||
      landing.support_email ||
      (landing.latitude != null && landing.longitude != null) ||
      (landing.show_branches && branches > 0)),
  );
}
