import axios, { type AxiosError, type InternalAxiosRequestConfig } from "axios";

import { clearOfflineIdentity } from "@/features/auth/offline-identity";
import { clearApiCache } from "@/lib/api-cache";
import { hostContext } from "@/lib/auth/host-context";
import { getLocale } from "@/lib/i18n/translations";
import { recordFailedRequest } from "@/lib/support/recent-failures";

import { createLimiter } from "./request-limiter";

export interface ApiError {
  type: string;
  title: string;
  status: number;
  detail: string;
  errors?: Record<string, string[]>;
}

const apiClient = axios.create({
  baseURL: "/api/v1",
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
  withCredentials: true,
  timeout: 30000,
});

/**
 * The host runs five PHP workers (`pm.max_children 5`, in
 * docs/deployment/host-evidence/HOST-EVIDENCE.json), shared by every user, the
 * cron caller and the devices. One browser takes at most three and leaves two
 * for everyone else; past five busy workers the host answers a plain HTML 503.
 */
export const MAX_IN_FLIGHT = 3;

const limiter = createLimiter(MAX_IN_FLIGHT);

// A slot is held only while the request is on the network, not while it waits
// in an interceptor. The bare `axios` calls (the CSRF cookie, the session
// refresh) stay outside the cap on purpose.
const send = axios.getAdapter(
  apiClient.defaults.adapter ?? axios.defaults.adapter,
);
apiClient.defaults.adapter = (config) => limiter.run(() => send(config));

apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  if (typeof window === "undefined") return config;

  // The UI's own resolver, so errors arrive in the language the screen is in.
  // This fell back to "en" while the UI falls back to DEFAULT_LOCALE ("am"):
  // a first visit read Amharic screens and English errors (production QA
  // 2026-10-11, P9).
  const locale = getLocale();
  if (config.headers) {
    config.headers["Accept-Language"] = locale;
  }

  // X-Tenant names the tenant wherever no hostname can.
  //
  // Without NEXT_PUBLIC_ROOT_DOMAIN — which is how production builds, because
  // the host serves no wildcard subdomains (M3) — the app runs on one host
  // (`ethr.et`), and the header is the only way to say which tenant is meant.
  // ResolveTenant honours it on the apex, its `www` alias and single-host
  // installs, in every environment (docs/decisions/
  // OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md). A custom domain or subdomain
  // the request arrived on still wins over a header naming someone else.
  //
  // Sent wherever the hostname names no organisation: the apex and its `www`
  // alias ("public"), and any host with no root domain configured ("unknown").
  // Until 2026-10-11 a configured root domain switched the header off
  // entirely, on the assumption that every organisation then lived on its
  // subdomain. Each organisation now chooses, and one that chose the shared
  // address signs in on the apex — where nothing but this header names it.
  // On a tenant host or the platform host the server ignores or refuses it,
  // so it is simply not sent there.
  //
  // A sign-in call names the organisation the person typed (`tenant` on the
  // request), and that wins over the stored one. The stored slug changes only
  // after a successful sign-in, so a browser that had signed in to one
  // organisation sent it with every attempt at another, and the server
  // checked the password against the wrong organisation (2026-10-17).
  const tenant = tenantHeaderValue(config.tenant);
  if (tenant && config.headers) {
    config.headers["X-Tenant"] = tenant;
  }

  return config;
});

/**
 * The `X-Tenant` value this page sends, or null where the hostname names the
 * organisation itself. Exported so a punch queued offline can carry it: the
 * service worker sends the queue with no page, and so no localStorage.
 *
 * `typed` is the organisation a sign-in form was given. Passed, it replaces
 * the stored one, and an empty value sends none.
 */
export function tenantHeaderValue(typed?: string): string | null {
  if (typeof window === "undefined") return null;
  const context = hostContext(
    window.location.host,
    process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || null,
  );
  if (context !== "public" && context !== "unknown") return null;
  if (typed !== undefined) return typed.trim().toLowerCase() || null;
  try {
    return localStorage.getItem("tenant");
  } catch {
    return null;
  }
}

const SAFE_METHODS = ["get", "head", "options"];

/**
 * Sanctum refuses a state-changing request with no XSRF cookie (419), and a
 * browser has none until something fetches /sanctum/csrf-cookie. Screens got
 * it as a side effect of an earlier request, or not at all: the reset page,
 * where account activation also lands, posted first and failed for every user
 * who opened it from an email (2026-10-09). So the client no longer relies on
 * the side effect. It fetches the cookie before a write when the cookie is
 * missing, which is once per browser. Axios reads the cookie into
 * X-XSRF-TOKEN after this runs.
 */
export function hasXsrfCookie(): boolean {
  return document.cookie.split("; ").some((c) => c.startsWith("XSRF-TOKEN="));
}

apiClient.interceptors.request.use(async (config) => {
  if (typeof window === "undefined") return config;

  const method = (config.method ?? "get").toLowerCase();
  if (!SAFE_METHODS.includes(method) && !hasXsrfCookie()) {
    await axios.get("/sanctum/csrf-cookie", { withCredentials: true });
  }

  return config;
});

/**
 * The `type` the API answers with when the tenant requires two-factor
 * authentication and this user has not set it up
 * (`RequireTenantMfaEnrolment`). Every endpoint outside the account-security
 * screen refuses them until they enrol.
 */
export const MFA_ENROLMENT_REQUIRED =
  "https://ethr.et/errors/mfa-enrolment-required";

/** Where enrolment happens; the API leaves this screen's endpoints open. */
export const MFA_ENROLMENT_PATH = "/profile/security";

/**
 * Where to send the browser for an enrolment refusal, or null when the error
 * is something else or the user is already on the setup screen (whose own
 * shell still makes requests the API refuses — redirecting from there would
 * reload it forever).
 */
export function mfaEnrolmentRedirect(
  error: Pick<AxiosError<ApiError>, "response">,
  pathname: string,
): string | null {
  if (
    error.response?.status !== 403 ||
    error.response.data?.type !== MFA_ENROLMENT_REQUIRED
  ) {
    return null;
  }
  if (pathname.startsWith(MFA_ENROLMENT_PATH)) return null;
  return `${MFA_ENROLMENT_PATH}?mfa=required`;
}

/**
 * The `type` the API answers with while someone still has the starting
 * password HR gave their batch (`RequirePasswordChange`). Every endpoint but
 * the password change, sign-out and the session refresh refuses them until
 * they choose their own.
 */
export const PASSWORD_CHANGE_REQUIRED =
  "https://ethr.et/errors/password-change-required";

/** Where the change happens. It stands outside the app shell and calls nothing the API refuses. */
export const PASSWORD_CHANGE_PATH = "/login/change-password";

/**
 * Where to send the browser for that refusal, or null when the error is
 * something else or the person is already on the change-password screen.
 */
export function passwordChangeRedirect(
  error: Pick<AxiosError<ApiError>, "response">,
  pathname: string,
): string | null {
  if (
    error.response?.status !== 403 ||
    error.response.data?.type !== PASSWORD_CHANGE_REQUIRED
  ) {
    return null;
  }
  if (pathname.startsWith(PASSWORD_CHANGE_PATH)) return null;
  return PASSWORD_CHANGE_PATH;
}

const BUSY_STATUSES = [502, 503, 504];
const MAX_BUSY_RETRIES = 2;

/** How long to wait before asking again: the host's own hint, else backoff with jitter. */
function busyDelayMs(retryAfter: unknown, attempt: number): number {
  const seconds = Number(retryAfter);
  if (
    retryAfter !== undefined &&
    retryAfter !== "" &&
    Number.isFinite(seconds) &&
    seconds >= 0
  ) {
    return Math.min(seconds, 5) * 1000;
  }
  return 400 * 2 ** attempt + Math.random() * 250;
}

let redirectingToEnrolment = false;

/** The refresh in flight, shared by every 401 that arrives while it runs. */
let refreshing: Promise<unknown> | null = null;

apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiError>) => {
    const originalRequest = error.config;

    // The host turned a read away because every PHP worker was busy. Its page
    // is plain HTML; the API's own deliberate 503s (devices, SCIM, a paused
    // portal section) carry a problem+json `type` and are answers, not
    // congestion. Writes are never sent again: one may have run. This sits
    // first so a request that recovers is not recorded as a failure.
    const busyAttempt = originalRequest?._busyRetries ?? 0;
    if (
      originalRequest !== undefined &&
      error.response !== undefined &&
      BUSY_STATUSES.includes(error.response.status) &&
      SAFE_METHODS.includes((originalRequest.method ?? "get").toLowerCase()) &&
      typeof error.response.data?.type !== "string" &&
      busyAttempt < MAX_BUSY_RETRIES
    ) {
      const wait = busyDelayMs(
        error.response.headers?.["retry-after"],
        busyAttempt,
      );
      await new Promise((resolve) => setTimeout(resolve, wait));
      originalRequest._busyRetries = busyAttempt + 1;

      return apiClient(originalRequest);
    }

    // A 419 is a stale XSRF cookie: present, so the request interceptor did
    // not fetch one, but from a session that has since ended (signed out in
    // another tab, or expired). Fetch a fresh one and send the write again,
    // once; a second 419 goes to the caller like any other refusal.
    const staleXsrf =
      error.response?.status === 419 &&
      originalRequest !== undefined &&
      !originalRequest._xsrfRetry;

    // Kept for Get help, which sends the last few with a question. A 401
    // that the refresh below recovers, or a 419 the retry below recovers, is
    // not a failure the person saw.
    if (error.response?.status !== 401 && !staleXsrf) {
      recordFailedRequest({
        method: originalRequest?.method,
        url: originalRequest?.url,
        status: error.response?.status,
        type: error.response?.data?.type,
      });
    }

    if (typeof window !== "undefined" && !redirectingToEnrolment) {
      // The password comes first: the API asks for it before it asks about
      // two-factor, and the change screen is the one place both let through.
      const target =
        passwordChangeRedirect(error, window.location.pathname) ??
        mfaEnrolmentRedirect(error, window.location.pathname);
      if (target) {
        // Several requests are refused at once on a page load; one navigation.
        redirectingToEnrolment = true;
        window.location.assign(target);
        return Promise.reject(error);
      }
    }

    if (staleXsrf) {
      originalRequest._xsrfRetry = true;
      await axios.get("/sanctum/csrf-cookie", { withCredentials: true });

      return apiClient(originalRequest);
    }

    // `skipAuthRefresh`: a 401 that is an answer, not an expired login. The
    // kiosk terminal has no session at all; its 401s mean "invalid token" or
    // "wrong PIN" and belong on the kiosk screen. Refreshing would fail and
    // the redirect dropped the shared terminal onto the login page (N48).
    if (
      error.response?.status === 401 &&
      originalRequest &&
      !originalRequest._retry &&
      !originalRequest.skipAuthRefresh
    ) {
      originalRequest._retry = true;

      try {
        // One refresh for every request that failed together. A page's
        // parallel calls each started their own, and the first to land
        // revoked the token the others were refreshing (production QA
        // 2026-10-11 saw four at once).
        refreshing ??= axios
          .post("/api/v1/auth/refresh", {}, { withCredentials: true })
          .finally(() => {
            refreshing = null;
          });
        await refreshing;

        return apiClient(originalRequest);
      } catch {
        clearOfflineIdentity();
        await clearApiCache();
        window.location.href = "/login";
      }
    }

    return Promise.reject(error);
  },
);

declare module "axios" {
  interface InternalAxiosRequestConfig {
    _retry?: boolean;
    _xsrfRetry?: boolean;
    _busyRetries?: number;
  }
  interface AxiosRequestConfig {
    /** Hand a 401 to the caller instead of refreshing the session. */
    skipAuthRefresh?: boolean;
    /**
     * The organisation a sign-in call names, sent as `X-Tenant` in place of
     * the stored one; "" sends none. Ignored where the host names it.
     */
    tenant?: string;
  }
}

export { apiClient };
