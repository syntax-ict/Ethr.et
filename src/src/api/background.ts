/**
 * Marks a request as background polling, not user activity.
 *
 * The tenant's idle timeout (`EnforceSessionIdleTimeout` in the API) still
 * checks such a request — an expired session is ended by the poll that finds
 * it — but does not let it extend the session. Without this the unread-count
 * poll on every page would keep an unattended tab signed in forever.
 *
 * Its own module, not `client.ts`, so the tests that mock `@/api/client` with
 * only `apiClient` do not lose it.
 */
export const BACKGROUND_HEADER = "X-ETHR-Background";

export function backgroundRequest(background = true): {
  headers?: Record<string, string>;
} {
  return background ? { headers: { [BACKGROUND_HEADER]: "1" } } : {};
}
