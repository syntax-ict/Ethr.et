/**
 * The last few requests this page sent that failed, kept in memory only, so
 * a help request can say what went wrong without the person copying an error.
 *
 * Paths lose their query string: a search box puts names and emails there.
 * Nothing here is persisted, and a reload starts empty. Requests to the help
 * endpoints themselves are left out, so asking for help about a failure does
 * not record the asking.
 */

export interface FailedRequest {
  method: string;
  path: string | null;
  status: number;
  type: string | null;
  at: string;
}

const LIMIT = 5;
const failures: FailedRequest[] = [];

const METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE"];

export function recordFailedRequest(input: {
  method?: string;
  url?: string;
  status?: number;
  type?: unknown;
}): void {
  const method = (input.method ?? "get").toUpperCase();
  const path = stripQuery(input.url);
  if (!METHODS.includes(method)) return;
  if (path?.startsWith("/support-requests")) return;
  if (path?.startsWith("/admin/support-requests")) return;

  failures.push({
    method,
    path,
    status: input.status ?? 0,
    type: typeof input.type === "string" ? input.type.slice(0, 200) : null,
    at: new Date().toISOString(),
  });
  if (failures.length > LIMIT) failures.splice(0, failures.length - LIMIT);
}

/** Newest last, at most five. */
export function recentFailedRequests(): FailedRequest[] {
  return failures.map((f) => ({ ...f }));
}

/** For tests. */
export function clearFailedRequests(): void {
  failures.length = 0;
}

function stripQuery(url: string | undefined): string | null {
  if (!url) return null;
  const path = url.split(/[?#]/, 1)[0] ?? "";
  return path === "" ? null : path.slice(0, 200);
}
