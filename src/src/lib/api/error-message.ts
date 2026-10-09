/**
 * The reason to show for a failed request: the first field error, else the
 * problem's `detail`, else `fallback`.
 *
 * Field errors come first because the API's 422 handler always sets `detail`
 * to the generic "The given data was invalid." and puts the real reason in
 * `errors`. Forms that showed `detail` told the user nothing useful. A device
 * registered at a private address, like the form's own placeholder
 * 192.168.1.100, was refused as "invalid" and never said why (audit N53).
 * For other refusals (403, 409) there are no field errors, and `detail` is the
 * reason.
 */
export function apiErrorMessage(err: unknown, fallback: string): string {
  return apiErrorDetail(err) || fallback;
}

/**
 * The server's own reason, as `apiErrorMessage` picks it, or `undefined` when
 * it gave none — for a caller that chooses its own fallback. Seventeen screens
 * read `response.data.detail` by hand instead, so a 422 showed the generic
 * "The given data was invalid." where this shows the field's reason
 * (redundancy audit, 2026-10-09).
 */
export function apiErrorDetail(err: unknown): string | undefined {
  const data = (
    err as {
      response?: {
        data?: { detail?: string; errors?: Record<string, string[]> };
      };
    }
  )?.response?.data;

  const fieldError = Object.values(data?.errors ?? {})[0]?.[0];

  return fieldError || data?.detail || undefined;
}
