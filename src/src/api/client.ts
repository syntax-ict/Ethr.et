import axios, { type AxiosError, type InternalAxiosRequestConfig } from "axios";

import { hostnameIsAuthoritative } from "@/lib/auth/tenant-host";

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

apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  if (typeof window === "undefined") return config;

  const locale = localStorage.getItem("locale") || "en";
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
  // Where a root domain is configured, the hostname is the selector and this
  // header is not sent: the apex routes to `{tenant}.{root}` instead. That
  // condition is exactly "the hostname is not authoritative": the second guard
  // this used to carry counted labels without knowing the root domain, and
  // could only ever disagree with the first one wrongly.
  if (!hostnameIsAuthoritative()) {
    const tenant = localStorage.getItem("tenant");
    if (tenant && config.headers) {
      config.headers["X-Tenant"] = tenant;
    }
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

let redirectingToEnrolment = false;

apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiError>) => {
    const originalRequest = error.config;

    if (typeof window !== "undefined" && !redirectingToEnrolment) {
      const target = mfaEnrolmentRedirect(error, window.location.pathname);
      if (target) {
        // Several requests are refused at once on a page load; one navigation.
        redirectingToEnrolment = true;
        window.location.assign(target);
        return Promise.reject(error);
      }
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
        await axios.post("/api/v1/auth/refresh", {}, { withCredentials: true });

        return apiClient(originalRequest);
      } catch {
        window.location.href = "/login";
      }
    }

    return Promise.reject(error);
  },
);

declare module "axios" {
  interface InternalAxiosRequestConfig {
    _retry?: boolean;
  }
  interface AxiosRequestConfig {
    /** Hand a 401 to the caller instead of refreshing the session. */
    skipAuthRefresh?: boolean;
  }
}

export { apiClient };
