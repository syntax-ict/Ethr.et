import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { registerLocale } from "@/lib/i18n/translations";
import amTranslations from "@/lib/i18n/locales/am.json";
import { AuthGuard } from "@/components/shared/auth-guard";

const replace = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn(), back: vi.fn() }),
}));

/**
 * AuthGuard wraps the whole `(dashboard)` layout, so it is the single gate in
 * front of every authenticated screen. The bug worth catching is not "an
 * anonymous visitor gets in" — it is protected content rendering for a moment
 * on its way out, which is a different and much easier mistake to make.
 */

const PROTECTED = "Payroll for September";

function renderGuard() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const utils = render(
    <QueryClientProvider client={queryClient}>
      <AuthGuard>
        <p>{PROTECTED}</p>
      </AuthGuard>
    </QueryClientProvider>,
  );
  return { ...utils, queryClient };
}

const ME_BODY = {
  user: { public_id: "01HZUSER", name: "Abebe", role: "hr_admin" },
  permissions: [],
  tenant: null,
};

function meSucceeds() {
  server.use(http.get("*/auth/me", () => HttpResponse.json(ME_BODY)));
}

/**
 * A 401 does not go straight to the guard. `api/client.ts` intercepts it, tries
 * `POST /auth/refresh` once, and replays the request if that works — so a test
 * that wants "the session is over" has to say the refresh fails too. Leaving it
 * unhandled produced the right outcome for the wrong reason: MSW's
 * `onUnhandledRequest: "error"` was failing the refresh by accident.
 */
function refreshFails() {
  server.use(
    http.post("*/auth/refresh", () => new HttpResponse(null, { status: 401 })),
  );
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("<AuthGuard>", () => {
  it("renders the page once the user is known", async () => {
    meSucceeds();
    renderGuard();

    expect(await screen.findByText(PROTECTED)).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it("shows nothing protected while /auth/me is still in flight", async () => {
    let release: (() => void) | null = null;
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    server.use(
      http.get("*/auth/me", async () => {
        await held;
        return HttpResponse.json(ME_BODY);
      }),
    );

    renderGuard();

    expect(screen.queryByText(PROTECTED)).not.toBeInTheDocument();

    // And it must not bounce a perfectly valid session to /login just because
    // the request is slow — that would log people out on a bad connection.
    expect(replace).not.toHaveBeenCalled();

    release!();
    expect(await screen.findByText(PROTECTED)).toBeInTheDocument();
  });

  it("sends an unauthenticated visitor to /login without rendering the page", async () => {
    refreshFails();
    server.use(
      http.get("*/auth/me", () => new HttpResponse(null, { status: 401 })),
    );

    renderGuard();

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
    expect(screen.queryByText(PROTECTED)).not.toBeInTheDocument();
  });

  it("stops rendering the page the moment a live session stops being valid", async () => {
    // The case this guard got wrong. TanStack keeps the last successful `data`
    // when a refetch fails, so after a revoked or expired token `isError` is
    // true while `user` is still populated. Checking only `!user` therefore
    // kept the dashboard on screen until the route change landed — for exactly
    // the user whose session had just been taken away. See
    // `sessions-api.ts`: revoking a session is a supported action, so this is
    // not a hypothetical.
    refreshFails();
    let authenticated = true;
    server.use(
      http.get("*/auth/me", () => {
        if (!authenticated) {
          return new HttpResponse(null, { status: 401 });
        }
        return HttpResponse.json(ME_BODY);
      }),
    );

    const { queryClient } = renderGuard();
    expect(await screen.findByText(PROTECTED)).toBeInTheDocument();

    authenticated = false;
    await queryClient.invalidateQueries({ queryKey: ["auth", "me"] });

    await waitFor(() =>
      expect(screen.queryByText(PROTECTED)).not.toBeInTheDocument(),
    );
    expect(replace).toHaveBeenCalledWith("/login");
  });

  it("does not log anyone out over a token that refreshes cleanly", async () => {
    // An expired access token is routine, and the client is built to replay the
    // request after refreshing. If the guard reacted to the first 401 the user
    // would be bounced to /login in the middle of an ordinary session.
    let refreshed = false;
    server.use(
      http.post("*/auth/refresh", () => {
        refreshed = true;
        return HttpResponse.json({ message: "ok" });
      }),
      http.get("*/auth/me", () => {
        if (!refreshed) {
          return new HttpResponse(null, { status: 401 });
        }
        return HttpResponse.json(ME_BODY);
      }),
    );

    renderGuard();

    expect(await screen.findByText(PROTECTED)).toBeInTheDocument();
    expect(refreshed).toBe(true);
    expect(replace).not.toHaveBeenCalled();
  });

  it("translates the loading state instead of hardcoding English", async () => {
    // `common.loading` already existed in both locales. The guard rendered the
    // English literal directly, so an Amharic user saw English on the one
    // screen every single session begins with. Asserting the English string
    // would prove nothing — it reads the same either way — so this renders in
    // Amharic, where a hardcoded literal cannot pass.
    registerLocale("am", amTranslations);
    localStorage.setItem("locale", "am");

    refreshFails();
    let release: (() => void) | null = null;
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    server.use(
      http.get("*/auth/me", async () => {
        await held;
        return new HttpResponse(null, { status: 401 });
      }),
    );

    renderGuard();

    expect(
      await screen.findByText(amTranslations["common.loading"]),
    ).toBeInTheDocument();

    release!();
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  });
});
