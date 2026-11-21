/**
 * Phone numbers, one definition for the browser.
 *
 * Mirrors `api/app/Support/EthiopianPhone.php` (`canonical`, `format`,
 * `isInternational`) so the form says yes and no to exactly the numbers the
 * server does. Change one and the other has to move with it.
 */

/** A phone holds digits and the usual separators, never letters. Mirrors the
 *  character check in `App\Rules\Phone`. */
const TYPED_LIKE_A_PHONE = /^[\d\s+().-]+$/;

/** Mirrors `EthiopianPhone::SUBSCRIBER`: nine digits, not starting with 0. */
const SUBSCRIBER = /^[1-9]\d{8}$/;

const digitsOf = (input: string): string => input.replace(/\D+/g, "");

/** True when the text could be a phone number at all (no letters). */
export function looksLikeAPhone(input: string): boolean {
  return TYPED_LIKE_A_PHONE.test(input.trim());
}

/**
 * The stored form, `+251911223344`, or null when this is not an Ethiopian
 * number. Accepts every shape people type: `0911…`, `+251911…`, `251911…`,
 * a bare `911…`, with or without spaces, dashes and brackets.
 */
export function canonicalPhone(input: string): string | null {
  if (!looksLikeAPhone(input)) return null;

  let digits = digitsOf(input);

  if (digits.startsWith("251")) {
    digits = digits.slice(3);
  } else if (digits.startsWith("0")) {
    digits = digits.slice(1);
  }

  return SUBSCRIBER.test(digits) ? `+251${digits}` : null;
}

/** An Ethiopian mobile number (`9…` or `7…`), the only kind that gets a text. */
export function isEthiopianMobile(input: string): boolean {
  const canonical = canonicalPhone(input);
  return canonical !== null && /^\+251[79]/.test(canonical);
}

/**
 * A number from another country, written with its country code: a plus and 8
 * to 15 digits. A malformed Ethiopian number is a typing mistake, not a foreign
 * number, so it never counts.
 */
export function isInternationalPhone(input: string): boolean {
  const trimmed = input.trim();

  if (!trimmed.startsWith("+") || !looksLikeAPhone(trimmed)) return false;

  const digits = digitsOf(trimmed);

  if (digits.startsWith("251")) return false;

  return /^[1-9]\d{7,14}$/.test(digits);
}

/** Splits nine national digits as `91 122 3344`; a short run is grouped as far as it goes. */
export function groupNationalDigits(digits: string): string {
  return [digits.slice(0, 2), digits.slice(2, 5), digits.slice(5, 9)]
    .filter(Boolean)
    .join(" ");
}

/**
 * The display form: `+251 91 122 3344`. A number that is not Ethiopian is
 * shown as it was entered, and an empty value stays empty.
 */
export function formatPhone(input: string | null | undefined): string {
  if (!input) return "";

  const canonical = canonicalPhone(input);

  return canonical
    ? `+251 ${groupNationalDigits(canonical.slice(4))}`
    : input.trim();
}

/**
 * A `tel:` link target. The dialler wants the number without spaces, dashes
 * or brackets, and an Ethiopian number in its full `+251` form.
 */
export function telHref(input: string | null | undefined): string {
  if (!input) return "";

  const canonical = canonicalPhone(input);

  if (canonical) return `tel:${canonical}`;

  const plus = input.trim().startsWith("+") ? "+" : "";

  return `tel:${plus}${digitsOf(input)}`;
}
