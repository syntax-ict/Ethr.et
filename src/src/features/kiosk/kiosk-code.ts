/**
 * The organisation's employee number format as the kiosk receives it:
 * `EMP-{SEQ:4}` is `{ prefix: "EMP-", width: 4, suffix: "" }`.
 */
export interface KioskCodeFormat {
  prefix: string;
  width: number;
  suffix: string;
}

/**
 * The full code a typed number stands for, or null when what was typed is
 * not a bare number. The pad has only digits, so `12` is `EMP-0012`. The
 * server does the same (KioskEmployeeCode), so a terminal that has not
 * refreshed its format still punches the right person; this only shows the
 * person whose code it is before they press Record.
 */
export function fullCode(
  typed: string,
  format: KioskCodeFormat | null | undefined,
): string | null {
  if (!format || !/^\d{1,8}$/.test(typed) || Number(typed) === 0) return null;
  return `${format.prefix}${String(Number(typed)).padStart(format.width, "0")}${format.suffix}`;
}
