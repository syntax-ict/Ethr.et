/**
 * The read-only guard of `guard.ts`, for a browser Claude Code drives through
 * the Playwright MCP server (`--init-page`; 11, section 5).
 *
 * Every API call that could change something is aborted before it leaves the
 * browser, except `POST /api/v1/auth/refresh`, which keeps the saved session
 * alive. Leave it off only for a Mode W session the owner has agreed to, in a
 * QA organisation.
 *
 * It sees requests a page makes. Start the server with
 * `--block-service-workers` as well: the app's service worker replays punches
 * queued while offline with a request of its own (`sw.js`, `flushQueue`).
 *
 * Kept free of imports so the MCP server can load it on its own.
 */
type Route = {
  request(): { method(): string; url(): string };
  fallback(): Promise<void>;
  abort(reason?: string): Promise<void>;
};
type Page = {
  context(): {
    route(url: RegExp, handler: (route: Route) => Promise<void>): Promise<void>;
    addInitScript(script: () => void): Promise<void>;
  };
};

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);
const ALLOWED_WRITES = [/\/api\/v1\/auth\/refresh$/];

export default async function readOnly({ page }: { page: Page }) {
  const context = page.context();
  await context.route(/\/api\/v1\//, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (
      SAFE_METHODS.has(request.method()) ||
      ALLOWED_WRITES.some((pattern) => pattern.test(path))
    ) {
      await route.fallback();
      return;
    }
    console.warn(`[read-only] blocked ${request.method()} ${path}`);
    await route.abort("blockedbyclient");
  });
  await context.addInitScript(() => {
    try {
      window.localStorage.setItem("locale", "en");
    } catch {
      // Storage can be unavailable on an error page.
    }
  });
}
