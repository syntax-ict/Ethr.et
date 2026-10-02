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

  // X-Tenant is a single-host development affordance, not a production one.
  //
  // Where NEXT_PUBLIC_ROOT_DOMAIN is configured the hostname is the tenant
  // selector and the API refuses this header outright (ResolveTenant only
  // honours it in local/testing). Sending it anyway would be harmless but
  // misleading: it would look like the client still chooses the tenant.
  //
  // Without a root domain — localhost development, tests — there is no
  // subdomain to read, so the header is the only way to say which tenant is
  // meant. That condition is exactly "the hostname is not authoritative": the
  // second guard this used to carry counted labels without knowing the root
  // domain, and could only ever disagree with the first one wrongly.
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

    if (
      error.response?.status === 401 &&
      originalRequest &&
      !originalRequest._retry
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
}

export { apiClient };
