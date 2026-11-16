/**
 * True when a request got no answer at all: the connection dropped, or the
 * request timed out. An axios error in that case has no `response`; one with a
 * response is the server's own answer (a refusal, a 500) and is not this.
 */
export function isNoAnswer(error: unknown): boolean {
  if (typeof error !== "object" || error === null) return false;
  if ("response" in error && (error as { response?: unknown }).response) {
    return false;
  }
  return true;
}
