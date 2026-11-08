"use client";

import { isFrontDoor, withOrganisation } from "@/lib/auth/entry-organisation";
import { TenantAddressAffix } from "@/components/shared/tenant-address-affix";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  Eye,
  EyeOff,
  ChevronRight,
  Loader2,
  KeyRound,
  MessageSquareText,
} from "lucide-react";
import { useEffect, useMemo, useState, type ReactNode } from "react";
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
import { TenantLookupErrorState } from "@/components/shared/tenant-lookup-error-state";
import { cn } from "@/lib/utils";

/**
 * What a person may type in the sign-in field, as AuthIdentifierResolver
 * names them. The organisation enables some of these, in its own order.
 */
const SIGN_IN_IDENTIFIERS = [
  "email",
  "phone",
  "employee_code",
  "username",
] as const;
type SignInIdentifier = (typeof SIGN_IN_IDENTIFIERS)[number];

/**
 * The organisation's list, keeping only types this form knows. Empty or
 * absent (an API older than K-44) is email, the server's own default.
 */
function knownIdentifiers(listed: string[] | undefined): SignInIdentifier[] {
  const known = (listed ?? []).filter((type): type is SignInIdentifier =>
    (SIGN_IN_IDENTIFIERS as readonly string[]).includes(type),
  );
  return known.length > 0 ? [...new Set(known)] : ["email"];
}

/**
 * The sign-in field accepts whatever the organisation enables (K-44). It was
 * an email field everywhere, so an employee activated in person, told to sign
 * in with their employee code, was refused before the server was asked.
 *
 * The email check stays only where email is the one way in. Elsewhere the
 * value only has to be there: the server resolves it, and refuses anything it
 * cannot match with the same words as a wrong password.
 */
function loginSchemaFor(emailOnly: boolean) {
  return loginSchema.extend({
    identifier: emailOnly
      ? z.string().email("auth.email_invalid")
      : z.string().trim().min(1, "auth.identifier_required"),
  });
}

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
  // Each message is a key: the form translates it where it is shown. A
  // malformed address used to be told "Invalid credentials", which sent
  // people to reset a password that was never checked. Replaced per
  // organisation by loginSchemaFor().
  identifier: z.string(),
  password: z.string().min(1, "auth.password_required"),
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

/**
 * The shared sign-in form. One form for every organisation and for ETHR's
 * own sign-in: on an organisation's front door it sits in the portal's hero
 * card, elsewhere in the two-panel layout, and the surface around it already
 * says whose page this is. So the form itself stays short: a heading, the
 * fields, the button, and the other ways in that this organisation offers.
 */
export function LoginForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const pathname = usePathname();
  const { t, locale } = useT();
  const {
    context: hostAppContext,
    tenantSlug: subdomainFromHost,
    tenantName,
    tenantContextLoading,
    tenantLookupError,
    tenantFieldVisible,
    tenantSignIn,
    tenantBillingOnly,
    onLanding,
    carryOrganisation,
  } = useAuthHostContext();
  const [showPassword, setShowPassword] = useState(false);
  const [capsLock, setCapsLock] = useState(false);
  const [serverError, setServerError] = useState("");
  const [ssoLoading, setSsoLoading] = useState(false);
  const [arrivedByHandoff, setArrivedByHandoff] = useState(false);

  // Where the organisation is known, what it enables; its sign-in page while
  // that loads, email as before. On the shared page the organisation is only
  // typed, so every type is possible and the server decides. A platform super
  // admin always signs in by email.
  const identifiers: SignInIdentifier[] =
    hostAppContext === "platform"
      ? ["email"]
      : hostAppContext === "tenant" || onLanding
        ? knownIdentifiers(tenantSignIn?.identifiers)
        : [...SIGN_IN_IDENTIFIERS];
  const emailOnly = identifiers.length === 1 && identifiers[0] === "email";
  const phoneOnly = identifiers.length === 1 && identifiers[0] === "phone";
  const schema = useMemo(() => loginSchemaFor(emailOnly), [emailOnly]);

  const {
    register,
    handleSubmit,
    setValue,
    setFocus,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({
    resolver: zodResolver(schema),
    defaultValues: {
      tenant:
        typeof window !== "undefined"
          ? (organisationFromEntryUrl() ?? localStorage.getItem("tenant") ?? "")
          : "",
      identifier: "",
      password: "",
    },
  });

  // Arriving from the apex hand-off (production QA 2026-10-11, P6): keep the
  // language and email the person was using, and say why they are here.
  useEffect(() => {
    const handoff = takeCanonicalHandoff();
    if (!handoff) return;

    if (handoff.lang) setLocale(handoff.lang);
    if (handoff.email) setValue("identifier", handoff.email);
    // Read once from the address bar — an external system — and the URL is
    // cleared as it is read, so this cannot be derived during render: a
    // second render would find nothing. The documented exception to the rule.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setArrivedByHandoff(true);
    setFocus(handoff.email ? "password" : "identifier");
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
      // `identifier`, which the server reads first; `email` is its alias for
      // clients older than K-44.
      const result = await login({
        identifier: data.identifier,
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
        // Only an email travels: the other page takes nothing else from a
        // URL, so an employee code would ride along for nothing.
        const carried = data.identifier.includes("@") ? data.identifier : "";
        window.location.assign(
          canonicalHandoffUrl(canonicalUrl, carried, locale),
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

  /**
   * Caps Lock, read off the key events the password field receives: the one
   * state a masked field cannot show, and the usual reason a right password
   * is refused.
   */
  function readCapsLock(event: React.KeyboardEvent<HTMLInputElement>) {
    setCapsLock(event.getModifierState("CapsLock"));
  }

  if (tenantLookupError) {
    return <TenantLookupErrorState kind={tenantLookupError} />;
  }

  const organisation = hostAppContext === "tenant";
  const organisationLabel = tenantContextLoading
    ? "…"
    : (tenantName ?? subdomainFromHost ?? "ETHR");

  const heading =
    hostAppContext === "platform"
      ? t("auth.sign_in_to_platform", "Sign in to ETHR Platform")
      : organisation
        ? t("auth.sign_in_to_org", "Sign in to :name", {
            name: organisationLabel,
          })
        : t("auth.sign_in_to", "Sign in to ETHR");

  // Named once in a sentence, once as a label: "employee code or email" and
  // "Employee code or email".
  const identifierName = (type: SignInIdentifier, inline: boolean) => {
    switch (type) {
      case "phone":
        return inline
          ? t("auth.identifier_phone_inline", "phone number")
          : t("auth.identifier_phone", "Phone number");
      case "employee_code":
        return inline
          ? t("auth.identifier_employee_code_inline", "employee code")
          : t("auth.identifier_employee_code", "Employee code");
      case "username":
        return inline
          ? t("auth.identifier_username_inline", "username")
          : t("auth.identifier_username", "Username");
      default:
        return inline
          ? t("auth.identifier_email_inline", "email")
          : t("auth.identifier_email", "Email");
    }
  };
  const identifierList = (inline: boolean) => {
    const [a, b, c, d] = identifiers.map((type, i) =>
      identifierName(type, inline || i > 0),
    );
    if (d)
      return t("auth.identifier_or_four", ":a, :b, :c or :d", { a, b, c, d });
    if (c) return t("auth.identifier_or_three", ":a, :b or :c", { a, b, c });
    if (b) return t("auth.identifier_or_two", ":a or :b", { a, b });
    return a;
  };
  const identifierLabel = emailOnly
    ? t("auth.email", "Email")
    : identifierList(false);
  const identifierInSentence = identifierList(true);

  const subtitle =
    hostAppContext === "platform"
      ? t(
          "auth.platform_subtitle",
          "Platform administration: super admin sign-in",
        )
      : emailOnly
        ? t(
            "auth.enter_credentials",
            "Enter your email and password to continue.",
          )
        : t(
            "auth.enter_credentials_identifier",
            "Enter your :label and password to continue.",
            { label: identifierInSentence },
          );

  // The surface around the form already carries the mark: the portal's hero,
  // the two-panel layout's brand panel, and on a phone its organisation band.
  // Only ETHR's own sign-in has nothing above the form on a phone, so only
  // there the tile is drawn, and only below the breakpoint where the brand
  // panel appears.
  const showMark = !organisation;
  // The address line matters where the organisation came from the path and
  // someone may have followed the wrong link: it names the address and offers
  // a way out. On the organisation's own host the hero and the heading
  // already say whose page this is, and there is nothing to change.
  const showAddress = organisation && onLanding && subdomainFromHost;
  const inPortal = organisation && isFrontDoor(pathname);

  // An organisation's page offers only the ways in it actually has: SSO once
  // it is configured, text-message codes once a gateway exists. Elsewhere the
  // organisation is not known yet, so both stay on offer.
  const offerSso = organisation && tenantSignIn ? tenantSignIn.sso : true;
  // A one-time code by email or by text message, whichever this organisation
  // can deliver: email wherever mail works, SMS once a gateway is configured.
  const offerOtp =
    organisation && tenantSignIn
      ? tenantSignIn.otp || Boolean(tenantSignIn.email_code)
      : true;
  // Not on an organisation's own address: the people there are its
  // employees, and a trial would start a new organisation.
  const offerTrial = !organisation && hostAppContext !== "platform";
  const alternatives = hostAppContext !== "platform" && (offerSso || offerOtp);
  // How the code arrives, where the organisation says; both elsewhere.
  const bySms = !(organisation && tenantSignIn) || tenantSignIn.otp;
  const byEmail =
    !(organisation && tenantSignIn) || Boolean(tenantSignIn.email_code);
  const codeHint =
    bySms && byEmail
      ? t("auth.code_hint_both", "Sent by text message or email")
      : bySms
        ? t("auth.code_hint_sms", "Sent by text message")
        : t("auth.code_hint_email", "Sent by email");

  const fieldError = "text-caption text-destructive";

  return (
    <div className={cn("mx-auto w-full", inPortal ? "max-w-md" : "max-w-sm")}>
      <div className={inPortal ? "mb-6" : "mb-8"}>
        {/* The tile beside the heading, not above it: on a phone it took a
            line of its own and pushed the form down. */}
        <div className="flex items-center gap-3">
          {showMark && (
            <div
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary shadow-sm lg:hidden"
              aria-hidden="true"
            >
              <span className="text-base font-bold text-primary-foreground">
                E
              </span>
            </div>
          )}
          <h1 className="min-w-0 text-title text-balance text-foreground">
            {heading}
          </h1>
        </div>
        <p className="mt-1.5 text-body text-muted-foreground">{subtitle}</p>
        {showAddress && (
          <p className="mt-3 flex flex-wrap items-center gap-x-2 text-caption text-muted-foreground">
            <span className="truncate">
              {t("auth.signing_in_at", "Signing in at :address", {
                address: `${window.location.host}/${subdomainFromHost}`,
              })}
            </span>
            <span aria-hidden="true">·</span>
            <Link
              prefetch={false}
              href="/login"
              className="font-medium text-primary underline-offset-4 hover:underline"
            >
              {t("auth.not_your_org", "Not yours?")}
            </Link>
          </p>
        )}
      </div>

      {arrivedByHandoff && (
        <div
          role="status"
          className="mb-5 rounded-lg border border-info-edge bg-info-soft p-3"
        >
          <p className="text-sm text-info-on-soft">
            {t(
              "auth.canonical_handoff",
              ":name signs in at this address. Enter your password again to continue.",
              { name: tenantName ?? subdomainFromHost ?? "ETHR" },
            )}
          </p>
        </div>
      )}

      {tenantBillingOnly && (
        <div
          role="status"
          className="mb-5 rounded-lg border border-warning-edge bg-warning-soft p-3"
        >
          <p className="text-sm text-warning-on-soft">
            {t(
              "auth.billing_only_notice",
              ":name's subscription has ended. Administrators can still sign in to see and pay the bill.",
              { name: tenantName ?? subdomainFromHost ?? "ETHR" },
            )}
          </p>
        </div>
      )}

      {/* noValidate: the browser's own bubble for a malformed email would
          speak over the form's translated message. */}
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-5">
        {tenantFieldVisible && (
          <div className="space-y-2">
            <Label htmlFor="tenant">
              {t("auth.org_subdomain", "Organization subdomain")}
            </Label>
            <div className="flex h-11 items-center rounded-md border border-input shadow-sm focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1">
              <TenantAddressAffix side="prefix" bordered />
              <Input
                id="tenant"
                {...register("tenant")}
                placeholder="acme"
                autoComplete="organization"
                autoCapitalize="none"
                spellCheck={false}
                className="h-full border-0 shadow-none focus-visible:ring-0"
              />
              <TenantAddressAffix side="suffix" bordered />
            </div>
            {errors.tenant && (
              <p className={fieldError}>
                {t(
                  errors.tenant.message!,
                  "Please enter your organization subdomain.",
                )}
              </p>
            )}
            <p className="text-caption text-muted-foreground">
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
          <Label htmlFor="identifier">{identifierLabel}</Label>
          <Input
            id="identifier"
            type={emailOnly ? "email" : "text"}
            inputMode={emailOnly ? "email" : phoneOnly ? "tel" : "text"}
            autoCapitalize="none"
            spellCheck={false}
            {...register("identifier")}
            autoComplete={emailOnly ? "email" : "username"}
            aria-invalid={errors.identifier ? true : undefined}
            aria-describedby={
              errors.identifier ? "identifier-error" : undefined
            }
            className="h-11"
          />
          {errors.identifier && (
            <p id="identifier-error" className={fieldError}>
              {errors.identifier.message === "auth.identifier_required"
                ? t("auth.identifier_required", "Enter your :label.", {
                    label: identifierInSentence,
                  })
                : t(errors.identifier.message!, "Enter a valid email address.")}
            </p>
          )}
        </div>

        <div className="space-y-2">
          <div className="flex items-center justify-between gap-3">
            <Label htmlFor="password">{t("auth.password", "Password")}</Label>
            {/* Password reset is per organisation; a platform super admin has
                none, so the link could only fail there (production QA
                2026-10-11, P14). Recovery is the maintenance create-admin
                step. */}
            {hostAppContext !== "platform" && (
              <Link
                prefetch={false}
                href={withOrganisation("/login/forgot", carryOrganisation)}
                className="text-caption font-medium text-primary underline-offset-4 hover:underline"
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
              onKeyDown={readCapsLock}
              onKeyUp={readCapsLock}
              aria-invalid={errors.password ? true : undefined}
              aria-describedby={
                [
                  errors.password ? "password-error" : null,
                  capsLock ? "caps-lock-note" : null,
                ]
                  .filter(Boolean)
                  .join(" ") || undefined
              }
              className="h-11 pr-11"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              aria-label={
                showPassword
                  ? t("auth.hide_password", "Hide password")
                  : t("auth.show_password", "Show password")
              }
              aria-pressed={showPassword}
              className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-md text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {showPassword ? (
                <EyeOff className="h-4 w-4" aria-hidden="true" />
              ) : (
                <Eye className="h-4 w-4" aria-hidden="true" />
              )}
            </button>
          </div>
          {capsLock && (
            <p
              id="caps-lock-note"
              role="status"
              className="text-caption text-warning-on-soft"
            >
              {t("auth.caps_lock_on", "Caps Lock is on.")}
            </p>
          )}
          {errors.password && (
            <p id="password-error" className={fieldError}>
              {t(errors.password.message!, "Enter your password.")}
            </p>
          )}
        </div>

        {serverError && (
          <div
            role="alert"
            className="rounded-lg border border-destructive-edge bg-destructive-soft p-3"
          >
            <p className="text-sm text-destructive-on-soft">{serverError}</p>
          </div>
        )}

        <Button
          type="submit"
          className="h-11 w-full text-base shadow-sm"
          disabled={isSubmitting}
        >
          {isSubmitting ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
              {t("auth.signing_in", "Signing in...")}
            </>
          ) : (
            t("auth.sign_in", "Sign in")
          )}
        </Button>

        {alternatives && (
          <div className="space-y-3 pt-1">
            <p className="flex items-center gap-3 text-caption text-muted-foreground">
              <span className="h-px flex-1 bg-border" aria-hidden="true" />
              {t("auth.other_ways", "Other ways to sign in")}
              <span className="h-px flex-1 bg-border" aria-hidden="true" />
            </p>
            {/* One row per way in, full width: the card is narrow at every
                screen size, and side by side the code label ran into its
                button's edge, worse in Amharic and Afaan Oromoo. Each row
                says what the method is, not only its name. */}
            <div className="grid gap-2">
              {offerSso && (
                <Button
                  type="button"
                  variant="outline"
                  className={METHOD_ROW}
                  onClick={handleSsoLogin}
                  disabled={ssoLoading}
                >
                  <MethodRow
                    icon={
                      ssoLoading ? (
                        <Loader2 className="animate-spin" aria-hidden="true" />
                      ) : (
                        <KeyRound aria-hidden="true" />
                      )
                    }
                    label={t("auth.sign_in_sso", "Sign in with SSO")}
                    hint={t(
                      "auth.sso_hint",
                      "Your organisation's single sign-on",
                    )}
                  />
                </Button>
              )}

              {offerOtp && (
                <Button variant="outline" className={METHOD_ROW} asChild>
                  <Link
                    prefetch={false}
                    href={withOrganisation("/login/otp", carryOrganisation)}
                  >
                    <MethodRow
                      icon={<MessageSquareText aria-hidden="true" />}
                      label={t(
                        "auth.sign_in_code_short",
                        "Use a one-time code",
                      )}
                      hint={codeHint}
                    />
                  </Link>
                </Button>
              )}
            </div>
          </div>
        )}

        {offerTrial && (
          <p className="pt-1 text-center text-sm text-muted-foreground">
            {t("auth.no_account", "Don't have an account?")}{" "}
            <Link
              prefetch={false}
              href="/register"
              className="font-medium text-primary underline-offset-4 hover:underline"
            >
              {t("auth.start_trial", "Start free trial")}
            </Link>
          </p>
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

/** One way in on the sign-in form: an outlined row, full width. */
const METHOD_ROW =
  "h-auto min-h-11 w-full justify-start gap-3 whitespace-normal px-3 py-2.5 text-left";

function MethodRow({
  icon,
  label,
  hint,
}: {
  icon: ReactNode;
  label: string;
  hint: string;
}) {
  return (
    <>
      <span
        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-foreground"
        aria-hidden="true"
      >
        {icon}
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="text-sm font-medium leading-snug">{label}</span>
        <span className="text-caption font-normal text-muted-foreground">
          {hint}
        </span>
      </span>
      <ChevronRight className="text-muted-foreground" aria-hidden="true" />
    </>
  );
}
