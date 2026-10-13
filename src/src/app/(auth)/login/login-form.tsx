"use client";

import { TenantAddressAffix } from "@/components/shared/tenant-address-affix";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  Eye,
  EyeOff,
  Loader2,
  KeyRound,
  MessageSquareText,
} from "lucide-react";
import { useEffect, useState } from "react";
import {
  canonicalHandoffUrl,
  takeCanonicalHandoff,
} from "@/lib/auth/canonical-handoff";
import { setLocale } from "@/lib/i18n/translations";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { initiateSso, login, prepareCsrfCookie } from "@/features/auth/sign-in";
import { InstallAppBanner } from "@/components/shared/install-app";
import { useT } from "@/lib/i18n/useT";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { TenantHostIndicator } from "@/components/shared/tenant-host-indicator";
import { OrgMark } from "@/components/shared/org-mark";
import { TenantLookupErrorState } from "@/components/shared/tenant-lookup-error-state";

const loginSchema = z.object({
  // Deliberately optional, and the server decides.
  //
  // Requiring it here locked platform super admins out of a fresh install:
  // they have no tenant by design, LoginRequest authenticates them before
  // tenant resolution, but the form refused to submit without a subdomain —
  // and on an installation with no tenants yet there is no valid value to
  // type. An unknown one is worse than useless, since ResolveTenant answers
  // 404 before login even runs.
  //
  // Left empty by an ordinary user the server replies "Organization is
  // required. Provide your subdomain.", which surfaces as a field error just
  // as the client-side rule did.
  tenant: z.string().optional(),
  email: z.string().email("auth.invalid_credentials"),
  password: z.string().min(1, "auth.invalid_credentials"),
});
type LoginForm = z.infer<typeof loginSchema>;

/**
 * The organisation named by `?org=`, which is where an organisation's entry URL
 * (`ethr.et/{slug}`, OrganisationEntryController) sends its people.
 *
 * It only prefills the field. Nothing is stored until the person signs in, so a
 * crafted link cannot change the organisation a signed-in browser sends in
 * X-Tenant. A value that is not a slug is ignored rather than shown.
 */
function organisationFromEntryUrl(): string | null {
  const org = new URLSearchParams(window.location.search)
    .get("org")
    ?.trim()
    .toLowerCase();

  return org && /^[a-z0-9][a-z0-9-]{0,62}$/.test(org) ? org : null;
}

export function LoginForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { t, locale } = useT();
  const {
    context: hostAppContext,
    tenantSlug: subdomainFromHost,
    tenantName,
    tenantContextLoading,
    tenantLookupError,
    tenantFieldVisible,
    tenantBrand,
    tenantSignIn,
    onLanding,
  } = useAuthHostContext();
  const [showPassword, setShowPassword] = useState(false);
  const [serverError, setServerError] = useState("");
  const [ssoLoading, setSsoLoading] = useState(false);
  const [arrivedByHandoff, setArrivedByHandoff] = useState(false);

  const {
    register,
    handleSubmit,
    setValue,
    setFocus,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      tenant:
        typeof window !== "undefined"
          ? (organisationFromEntryUrl() ?? localStorage.getItem("tenant") ?? "")
          : "",
      email: "",
      password: "",
    },
  });

  // Arriving from the apex hand-off (production QA 2026-10-11, P6): keep the
  // language and email the person was using, and say why they are here.
  useEffect(() => {
    const handoff = takeCanonicalHandoff();
    if (!handoff) return;

    if (handoff.lang) setLocale(handoff.lang);
    if (handoff.email) setValue("email", handoff.email);
    // Read once from the address bar — an external system — and the URL is
    // cleared as it is read, so this cannot be derived during render: a
    // second render would find nothing. The documented exception to the rule.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setArrivedByHandoff(true);
    setFocus(handoff.email ? "password" : "email");
  }, [setValue, setFocus]);

  async function onSubmit(data: LoginForm) {
    setServerError("");

    const effectiveTenant =
      subdomainFromHost ?? (data.tenant ?? "").trim().toLowerCase();

    // Where the organisation lives is the server's call, not this form's.
    // Until 2026-10-11 a configured root domain made this form send every
    // apex sign-in to `{slug}.ethr.et` before asking; but an organisation may
    // have chosen the shared address, and only the server knows. It answers
    // 409 `canonical-address` (handled below) when the sign-in belongs on
    // another host, before it looks at the password.

    try {
      await prepareCsrfCookie();
    } catch {
      setServerError(
        t(
          "auth.server_error",
          "Unable to connect to the server. Please try again later.",
        ),
      );
      return;
    }

    try {
      const result = await login({
        email: data.email,
        password: data.password,
        tenant: effectiveTenant,
      });

      // A super admin signs in without one; storing "" would then be sent as
      // an X-Tenant header on every later request and resolve to nothing.
      if (effectiveTenant !== "") {
        localStorage.setItem("tenant", effectiveTenant);
      } else {
        localStorage.removeItem("tenant");
      }

      if (result.mfa_required) {
        sessionStorage.setItem("mfa_pending", "true");
        router.push("/login/mfa");
        return;
      }

      // One QueryClient serves the whole app (`app/providers.tsx` creates it in
      // `useState` and never replaces it), and reaching this form does not
      // necessarily mean the page was reloaded — `AuthGuard` sends a failed session
      // here with `router.replace`, which keeps the JS context alive. Navigating on
      // to /dashboard with `router.push` would then hand the next person whatever
      // the previous one had cached, including across tenants.
      //
      // Authenticating starts a new session, so it starts a new cache.
      queryClient.clear();
      router.push("/dashboard");
    } catch (err: unknown) {
      const axiosError = err as {
        response?: {
          status?: number;
          data?: {
            detail?: string;
            errors?: Record<string, string[]>;
            canonical_url?: string | null;
          };
        };
        request?: unknown;
      };

      // 409 canonical-address: this organisation signs in at its own address
      // (its verified custom domain, or its subdomain), never here. The server
      // answers before it checks the password, so nothing was signed in; the
      // person signs in again there, where the host-only session cookie will
      // live. Only an http(s) URL is followed.
      const canonicalUrl =
        axiosError.response?.status === 409
          ? axiosError.response.data?.canonical_url
          : null;
      if (canonicalUrl && /^https?:\/\//i.test(canonicalUrl)) {
        window.location.assign(
          canonicalHandoffUrl(canonicalUrl, data.email, locale),
        );
        return;
      }

      // RFC-7807 puts the useful text in `errors`, while `detail` stays generic
      // ("The given data was invalid."). The subdomain rule moved server-side,
      // so without this the user who omits it is told nothing actionable.
      const fieldError = Object.values(
        axiosError.response?.data?.errors ?? {},
      )[0]?.[0];

      if (!axiosError.response) {
        setServerError(
          t(
            "auth.server_error",
            "Unable to connect to the server. Please try again later.",
          ),
        );
      } else if (
        axiosError.response.status &&
        axiosError.response.status >= 500
      ) {
        setServerError(
          t(
            "auth.server_error",
            "Unable to connect to the server. Please try again later.",
          ),
        );
      } else {
        setServerError(
          fieldError ||
            axiosError.response.data?.detail ||
            t(
              "auth.invalid_credentials",
              "Invalid credentials. Please try again.",
            ),
        );
      }
    }
  }

  async function handleSsoLogin() {
    const tenant =
      subdomainFromHost ??
      (document.getElementById("tenant") as HTMLInputElement)?.value
        ?.trim()
        .toLowerCase();

    if (!tenant) {
      setServerError(
        t("auth.enter_subdomain", "Please enter your organization subdomain."),
      );
      return;
    }

    setSsoLoading(true);
    setServerError("");

    try {
      const redirectUrl = await initiateSso(tenant);
      localStorage.setItem("tenant", tenant);
      // `assign()` rather than `location.href = …`: identical behaviour, but an
      // assignment to a global reads as a mutation to the compiler
      // (react-hooks/immutability). A full navigation is required here — this
      // leaves the app for the identity provider, so the router cannot serve.
      window.location.assign(redirectUrl);
    } catch (err: unknown) {
      const axiosError = err as {
        response?: { status?: number; data?: { detail?: string } };
      };
      if (axiosError.response?.status === 422) {
        setServerError(
          t(
            "auth.sso_not_configured_org",
            "SSO is not configured for this organization.",
          ),
        );
      } else {
        setServerError(
          axiosError.response?.data?.detail ??
            t("auth.sso_failed", "SSO login failed. Please try again."),
        );
      }
    } finally {
      setSsoLoading(false);
    }
  }

  if (tenantLookupError) {
    return <TenantLookupErrorState kind={tenantLookupError} />;
  }

  const heading =
    hostAppContext === "platform"
      ? t("auth.sign_in_to_platform", "Sign in to ETHR Platform")
      : hostAppContext === "tenant"
        ? t("auth.sign_in_to_org", "Sign in to :name", {
            name: tenantContextLoading
              ? "…"
              : (tenantName ?? subdomainFromHost ?? "ETHR"),
          })
        : t("auth.sign_in_to", "Sign in to ETHR");

  const subtitle =
    hostAppContext === "platform"
      ? t(
          "auth.platform_subtitle",
          "Platform administration — super admin sign-in",
        )
      : t(
          "auth.enter_credentials",
          "Enter your credentials to access your account",
        );

  // An organisation's page offers only the ways in it actually has: SSO once
  // it is configured, text-message codes once a gateway exists. Elsewhere the
  // organisation is not known yet, so both stay on offer.
  const offerSso =
    hostAppContext === "tenant" && tenantSignIn ? tenantSignIn.sso : true;
  const offerOtp =
    hostAppContext === "tenant" && tenantSignIn ? tenantSignIn.otp : true;
  // Not on an organisation's own address: the people there are its
  // employees, and a trial would start a new organisation.
  const offerTrial = hostAppContext !== "tenant";
  return (
    <div className="w-full max-w-sm mx-auto">
      <div className="mb-8">
        {hostAppContext === "tenant" ? (
          <OrgMark
            name={tenantName}
            logoUrl={tenantBrand?.logo_url}
            className="h-11 w-11"
            initialClassName="text-base"
          />
        ) : (
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary shadow-sm">
            <span className="text-base font-bold text-primary-foreground">
              E
            </span>
          </div>
        )}
        <h1 className="mt-6 text-2xl font-bold tracking-tight text-foreground">
          {heading}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">{subtitle}</p>
      </div>

      {arrivedByHandoff && (
        <div role="status" className="mb-5 rounded-lg bg-primary/10 p-3">
          <p className="text-sm text-foreground">
            {t(
              "auth.canonical_handoff",
              ":name signs in at this address. Enter your password again to continue.",
              { name: tenantName ?? subdomainFromHost ?? "ETHR" },
            )}
          </p>
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
        {hostAppContext === "tenant" && subdomainFromHost && (
          <TenantHostIndicator
            name={tenantName}
            slug={subdomainFromHost}
            loading={tenantContextLoading}
            logoUrl={tenantBrand?.logo_url}
            // On the landing the organisation is in the path, not the host;
            // and someone who followed the wrong link can still choose.
            address={
              onLanding
                ? `${window.location.host}/${subdomainFromHost}`
                : undefined
            }
            changeHref={onLanding ? "/login" : undefined}
          />
        )}

        {tenantFieldVisible && (
          <div className="space-y-2">
            <Label htmlFor="tenant">
              {t("auth.org_subdomain", "Organization subdomain")}
            </Label>
            <div className="flex items-center rounded-md border border-input focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1">
              <TenantAddressAffix side="prefix" bordered />
              <Input
                id="tenant"
                {...register("tenant")}
                placeholder="acme"
                autoComplete="organization"
                className="border-0 focus-visible:ring-0"
              />
              <TenantAddressAffix side="suffix" bordered />
            </div>
            {errors.tenant && (
              <p className="text-xs text-destructive">
                {t(
                  errors.tenant.message!,
                  "Please enter your organization subdomain.",
                )}
              </p>
            )}
            <p className="text-xs text-muted-foreground">
              {t(
                "auth.subdomain_hint",
                "Don't know your subdomain? Check the invitation email or ask your administrator.",
              )}{" "}
              <Link
                prefetch={false}
                href="/login/find"
                className="font-medium text-primary underline-offset-4 hover:underline"
              >
                {t("auth.find_org_link", "Find your organisation by email")}
              </Link>
            </p>
          </div>
        )}

        <div className="space-y-2">
          <Label htmlFor="email">{t("auth.email", "Email")}</Label>
          <Input
            id="email"
            type="email"
            {...register("email")}
            autoComplete="email"
          />
          {errors.email && (
            <p className="text-xs text-destructive">
              {t(errors.email.message!, "Please enter a valid email.")}
            </p>
          )}
        </div>

        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label htmlFor="password">{t("auth.password", "Password")}</Label>
            {/* Password reset is per organisation; a platform super admin has
                none, so the link could only fail there (production QA
                2026-10-11, P14). Recovery is the maintenance create-admin
                step. */}
            {hostAppContext !== "platform" && (
              <Link
                prefetch={false}
                href="/login/forgot"
                className="text-xs font-medium text-primary hover:underline"
              >
                {t("auth.forgot_password", "Forgot password?")}
              </Link>
            )}
          </div>
          <div className="relative">
            <Input
              id="password"
              type={showPassword ? "text" : "password"}
              {...register("password")}
              autoComplete="current-password"
              className="pr-10"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              aria-label={
                showPassword
                  ? t("auth.hide_password", "Hide password")
                  : t("auth.show_password", "Show password")
              }
              className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground transition-colors hover:text-foreground"
            >
              {showPassword ? (
                <EyeOff className="h-4 w-4" />
              ) : (
                <Eye className="h-4 w-4" />
              )}
            </button>
          </div>
          {errors.password && (
            <p className="text-xs text-destructive">
              {t(errors.password.message!, "Password is required.")}
            </p>
          )}
        </div>

        {serverError && (
          <div role="alert" className="rounded-lg bg-destructive/10 p-3">
            <p className="text-sm text-destructive">{serverError}</p>
          </div>
        )}

        <Button
          type="submit"
          className="h-11 w-full shadow-sm"
          disabled={isSubmitting}
        >
          {isSubmitting ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" />
              {t("auth.signing_in", "Signing in...")}
            </>
          ) : (
            t("auth.sign_in", "Sign In")
          )}
        </Button>

        {hostAppContext !== "platform" &&
          (offerSso || offerOtp || offerTrial) && (
            <>
              <div className="relative flex items-center py-1">
                <div className="flex-1 border-t border-border" />
                <span className="px-3 text-xs text-muted-foreground">
                  {t("auth.or", "or")}
                </span>
                <div className="flex-1 border-t border-border" />
              </div>

              {offerSso && (
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 w-full"
                  onClick={handleSsoLogin}
                  disabled={ssoLoading}
                >
                  {ssoLoading ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <KeyRound className="mr-2 h-4 w-4" />
                  )}
                  {t("auth.sign_in_sso", "Sign in with SSO")}
                </Button>
              )}

              {offerOtp && (
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 w-full"
                  asChild
                >
                  <Link prefetch={false} href="/login/otp">
                    <MessageSquareText className="mr-2 h-4 w-4" />
                    {t("auth.sign_in_otp", "Sign in with a text message code")}
                  </Link>
                </Button>
              )}

              {offerTrial && (
                <p className="text-center text-sm text-muted-foreground">
                  {t("auth.no_account", "Don't have an account?")}{" "}
                  <Link
                    prefetch={false}
                    href="/register"
                    className="font-medium text-primary hover:underline"
                  >
                    {t("auth.start_trial", "Start free trial")}
                  </Link>
                </p>
              )}
            </>
          )}
      </form>

      {/* Most people arrive here from a poster or a link on their phone;
          the installed app opens straight to check-in next time. */}
      {onLanding && (
        <div className="mt-6">
          <InstallAppBanner />
        </div>
      )}
    </div>
  );
}
