import { z } from "zod";

import {
  canonicalPhone,
  isEthiopianMobile,
  isInternationalPhone,
} from "@/lib/phone";

/**
 * Client-side mirrors of the backend's validation rules.
 *
 * These exist to fail *early and next to the field*, not to replace the server:
 * `api/app/Http/Requests/**` stays authoritative, and anything these rules let
 * through still gets rejected there and routed back inline by
 * `applyServerErrors`. The rule that must never be mirrored here is one the
 * client cannot actually evaluate — uniqueness, plan limits, cross-tenant
 * existence — because a guess would either block a legitimate value or promise
 * an acceptance the server then refuses.
 *
 * Messages are i18n **keys**, resolved at render time by the field's `t()`. A
 * literal English string here would bypass the translation layer entirely and
 * is the reason the i18n gate exists.
 *
 * Each rule below names the backend rule it mirrors so the two can be diffed
 * when either moves.
 */

/**
 * Phone rules. The shapes themselves live in `@/lib/phone`, which mirrors
 * `EthiopianPhone` on the server: separators (spaces, dashes, dots, brackets)
 * are ignored and `0911…`, `+251911…`, `251911…` and a bare `911…` are one
 * number, exactly as the server reads them. Rejecting `0911 22 33 44` in the
 * browser while the server accepts it is the bug that made registration
 * unusable for most Ethiopian users.
 */

/** Mirrors `RegisterTenantRequest`'s `subdomain` regex. */
const SUBDOMAIN = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

/**
 * Reserved slugs, mirroring `Tenant::RESERVED_SUBDOMAINS` exactly (verified
 * against `api/app/Models/Tenant.php:35`). Advisory only — `Rule::notIn` on the
 * server is the enforcement, and this copy must be re-checked whenever that
 * constant moves. Telling the user here just saves a round trip.
 */
export const RESERVED_SUBDOMAINS = [
  "admin",
  "api",
  "app",
  "www",
  "mail",
  "smtp",
  "ftp",
  "cdn",
  "static",
  "assets",
  "status",
  "support",
  "help",
  "docs",
  "staging",
  "dev",
  "test",
] as const;

export const rules = {
  /** `required|string|min:2|max:255` — the shape most name fields use. */
  name: (max = 255) =>
    z
      .string()
      .trim()
      .min(2, "validation.name_min")
      .max(max, "validation.too_long"),

  /** `required|string|email|max:255`. */
  email: () =>
    z
      .string()
      .trim()
      .min(1, "validation.required")
      .email("validation.email")
      .max(255, "validation.too_long"),

  /** An Ethiopian number in any typed shape: `App\Rules\Phone::ethiopian`
   *  semantics, landline included. Sign-up and the sign-in code narrow it with
   *  `mobilePhone()`. */
  phone: () =>
    z
      .string()
      .trim()
      .refine((v) => canonicalPhone(v) !== null, "validation.phone"),

  /** An Ethiopian mobile number, for SMS: mirrors `Phone::ethiopianMobile()`. */
  mobilePhone: () =>
    z
      .string()
      .trim()
      .refine((v) => isEthiopianMobile(v), "auth.sms_mobile_only"),

  /** A number that is recorded, not texted: Ethiopian in any shape, or a
   *  foreign number with its country code. Mirrors `Phone::any()`. */
  anyPhone: () =>
    z
      .string()
      .trim()
      .refine(
        (v) => canonicalPhone(v) !== null || isInternationalPhone(v),
        "validation.phone_any",
      ),

  /** Optional `anyPhone()`: an empty string means "not provided". */
  optionalPhone: () =>
    z
      .string()
      .trim()
      .refine(
        (v) =>
          v === "" || canonicalPhone(v) !== null || isInternationalPhone(v),
        "validation.phone_any",
      ),

  /** Mirrors `RegisterTenantRequest.subdomain`, minus the uniqueness check
   *  (server-only — the client cannot know what is taken). */
  subdomain: () =>
    z
      .string()
      .trim()
      .toLowerCase()
      .min(3, "validation.subdomain_min")
      .max(63, "validation.too_long")
      .regex(SUBDOMAIN, "validation.subdomain_format")
      .refine(
        (v) =>
          !RESERVED_SUBDOMAINS.includes(
            v as (typeof RESERVED_SUBDOMAINS)[number],
          ),
        "validation.subdomain_reserved",
      ),

  /** Mirrors `PasswordPolicy::DEFAULTS.min_length` (the platform floor — a
   *  tenant may configure stricter, which only the server can know, so a
   *  stricter policy surfaces as a server error rather than a client one). */
  password: () => z.string().min(8, "validation.password_min"),

  /** Free text with a length ceiling — `nullable|string|max:N`. */
  text: (max = 255) => z.string().trim().max(max, "validation.too_long"),

  /** Required free text. */
  requiredText: (max = 255) =>
    z
      .string()
      .trim()
      .min(1, "validation.required")
      .max(max, "validation.too_long"),

  /** A `<select>` whose empty option must not be submitted. */
  select: () => z.string().min(1, "validation.select_required"),

  /** ULID public_id — `exists:*,public_id` on the server. Shape only. */
  publicId: () => z.string().length(26, "validation.select_required"),

  /**
   * Money, entered in ETB and stored as integer minor units (convention #3).
   *
   * Takes the string an `<input>` yields, not a number: `valueAsNumber` turns
   * an empty field into `NaN`, which reads as "0.00 ETB" rather than "you left
   * this blank". Two decimal places, because a third would be silently
   * truncated on the way to cents.
   */
  etb: (options?: { min?: number; max?: number }) =>
    z
      .string()
      .trim()
      .min(1, "validation.required")
      .regex(/^\d+(\.\d{1,2})?$/, "validation.amount")
      .refine(
        (v) => options?.min === undefined || Number(v) >= options.min,
        "validation.amount_min",
      )
      .refine(
        (v) => options?.max === undefined || Number(v) <= options.max,
        "validation.amount_max",
      ),

  /** Non-negative whole number from a text/number input. */
  integer: (options?: { min?: number; max?: number }) =>
    z
      .string()
      .trim()
      .min(1, "validation.required")
      .regex(/^\d+$/, "validation.integer")
      .refine(
        (v) => options?.min === undefined || Number(v) >= options.min,
        "validation.amount_min",
      )
      .refine(
        (v) => options?.max === undefined || Number(v) <= options.max,
        "validation.amount_max",
      ),

  /** ISO `YYYY-MM-DD`, the value contract every date control in this app uses
   *  — including `DualCalendarDateInput`, which keeps that format in both
   *  Gregorian and Ethiopian entry modes. */
  date: () => z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "validation.date"),

  optionalDate: () =>
    z
      .string()
      .refine(
        (v) => v === "" || /^\d{4}-\d{2}-\d{2}$/.test(v),
        "validation.date",
      ),

  /** `url` with an http(s) scheme — mirrors `Rules\ExternalUrl`'s shape check.
   *  The private-IP/SSRF half is server-only and must stay that way. */
  url: () =>
    z
      .string()
      .trim()
      .url("validation.url")
      .refine((v) => /^https?:\/\//i.test(v), "validation.url_scheme"),
} as const;

/**
 * `end` must not fall before `start`. Applied with `.superRefine` so the
 * message lands on the end field, where the user can act on it.
 */
export function dateRangeRefinement<T extends Record<string, unknown>>(
  startKey: keyof T & string,
  endKey: keyof T & string,
) {
  return (data: T, ctx: z.RefinementCtx) => {
    const start = data[startKey];
    const end = data[endKey];
    if (typeof start !== "string" || typeof end !== "string") return;
    if (!start || !end) return;
    if (end < start) {
      ctx.addIssue({
        code: "custom",
        path: [endKey],
        message: "validation.date_range",
      });
    }
  };
}

/**
 * Resolve a schema message through i18n.
 *
 * Schemas carry keys so they stay locale-agnostic and testable; this turns one
 * into display text at render. A message that is not a known key (a server
 * string, already localized by the backend's own lang files) passes through.
 */
export function fieldMessage(
  t: (key: string, fallback?: string) => string,
  message: string | undefined,
): string | undefined {
  if (!message) return undefined;
  if (!message.includes(".")) return message;
  const translated = t(message, message);
  return translated === message ? message : translated;
}
