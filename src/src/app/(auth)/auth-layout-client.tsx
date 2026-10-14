"use client";

import { useEffect } from "react";
import Link from "next/link";
import {
  CalendarDays,
  CheckCircle2,
  Clock,
  LifeBuoy,
  Mail,
  Phone,
  Receipt,
  ShieldCheck,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { setLocale } from "@/lib/i18n/translations";
import { LanguageSwitcher } from "@/components/shared/language-switcher";
import { ProductStoryAnimation } from "@/components/shared/product-story-animation";
import { OrgMark } from "@/components/shared/org-mark";
import {
  useAuthHostContext,
  type TenantLanding,
} from "@/lib/auth/use-auth-host-context";
import { useTheme } from "next-themes";
import { applyBrandToDocument } from "@/features/branding/TenantBrandingProvider";
import {
  panelBackground,
  type LandingCover,
} from "@/lib/auth/landing-background";
import { usePathname } from "next/navigation";
import { OrganisationPortal } from "@/features/auth/components/organisation-portal";

const taglineKeys = [
  "layout_multi_tenant",
  "layout_offline",
  "layout_tax",
  "layout_bilingual",
] as const;

/**
 * The shared sign-in layout. One design for every organisation: on an
 * organisation's own address - ethr.et/{slug}, its subdomain or its domain -
 * the brand panel carries that organisation's logo, name, colours and its own
 * words, and ETHR steps back to "Powered by ETHR". On the generic sign-in it
 * is ETHR's.
 */
export function AuthLayoutClient({ children }: { children: React.ReactNode }) {
  const { t } = useT();
  const {
    context,
    tenantName,
    tenantBrand,
    tenantLanding,
    tenantDefaultLocale,
    tenantContextLoading,
    onLanding,
  } = useAuthHostContext();
  const organisation = context === "tenant";
  // The portal treatment belongs to the organisation's front door alone: its
  // entry URL and its /login. Forgot, reset and the other auth pages keep the
  // focused two-panel layout.
  const pathname = usePathname();
  const portal = organisation && (onLanding || pathname === "/login");

  // The colours go on the document so every control on the page - the
  // button, focus rings, links - takes them, as they do once signed in.
  const primary = tenantBrand?.primary_color ?? null;
  const accent = tenantBrand?.accent_color ?? null;
  // Not under high contrast: the person's accessibility choice wins.
  const { resolvedTheme } = useTheme();
  const highContrast = resolvedTheme === "high-contrast";
  useEffect(() => {
    if (!organisation || highContrast) return;
    return applyBrandToDocument({
      primary_color: primary,
      accent_color: accent,
    });
  }, [organisation, primary, accent, highContrast]);

  // A first visit opens in the organisation's language; a choice the person
  // already made on this device stands. A link that names a language
  // (`?lang=am`, the hreflang alternates the server emits) opens in it.
  useEffect(() => {
    if (!organisation) return;
    const asked = new URLSearchParams(window.location.search).get("lang");
    if (asked === "en" || asked === "am") {
      setLocale(asked);
      return;
    }
    if (!tenantDefaultLocale) return;
    try {
      if (localStorage.getItem("locale")) return;
    } catch {
      return;
    }
    setLocale(tenantDefaultLocale);
  }, [organisation, tenantDefaultLocale]);

  // The tab names the organisation, as its own page should.
  useEffect(() => {
    if (!organisation || !tenantName) return;
    const previous = document.title;
    document.title = `${t("auth.sign_in", "Sign In")} · ${tenantName}`;
    return () => {
      document.title = previous;
    };
  }, [organisation, tenantName, t]);

  if (portal) {
    return <OrganisationPortal>{children}</OrganisationPortal>;
  }

  return (
    <div className="flex min-h-screen">
      {/* Left panel — brand */}
      <div
        className="relative hidden w-1/2 overflow-hidden bg-primary lg:flex lg:flex-col"
        style={
          organisation && !highContrast
            ? panelBackground(
                tenantLanding,
                tenantBrand?.secondary_color,
                tenantBrand?.cover,
              )
            : undefined
        }
      >
        {/* Subtle grid pattern overlay */}
        <div className="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:40px_40px]" />
        <div className="absolute bottom-0 left-0 h-1/3 w-full bg-gradient-to-t from-black/10 to-transparent" />

        <div className="relative flex flex-1 flex-col items-center justify-center overflow-y-auto p-12">
          {organisation ? (
            <OrganisationPanel
              name={tenantName}
              logoUrl={tenantBrand?.logo_url}
              landing={tenantLanding}
              loading={tenantContextLoading}
            />
          ) : (
            <div className="max-w-md text-center">
              <Link
                prefetch={false}
                href="/"
                className="inline-flex items-center gap-2.5"
              >
                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary-foreground/20 backdrop-blur-sm">
                  <span className="text-xl font-bold text-primary-foreground">
                    E
                  </span>
                </div>
              </Link>
              <h2 className="mt-8 text-3xl font-bold leading-tight text-primary-foreground">
                {t("auth.layout_title", "Ethiopian Workforce Operating System")}
              </h2>
              <p className="mt-4 text-base leading-relaxed text-primary-foreground/75">
                {t(
                  "auth.layout_subtitle",
                  "Manage employees, attendance, payroll and leave in one platform built for Ethiopian organizations.",
                )}
              </p>

              <ProductStoryAnimation />

              <div className="mt-8 flex flex-wrap justify-center gap-x-4 gap-y-1.5 border-t border-white/10 pt-6 text-left">
                {taglineKeys.map((key) => (
                  <div key={key} className="flex items-center gap-1.5">
                    <CheckCircle2
                      className="h-3.5 w-3.5 shrink-0 text-primary-foreground/50"
                      aria-hidden="true"
                    />
                    <span className="text-xs text-primary-foreground/70">
                      {t(`auth.${key}`)}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Tibeb pattern at bottom */}
        <div className="relative h-1 w-full bg-[repeating-linear-gradient(90deg,rgba(255,255,255,0.3)_0px,rgba(255,255,255,0.3)_8px,rgba(232,168,56,0.5)_8px,rgba(232,168,56,0.5)_16px,rgba(255,255,255,0.3)_16px,rgba(255,255,255,0.3)_24px,transparent_24px,transparent_32px)]" />
      </div>

      {/* Right panel — form */}
      <div className="flex flex-1 flex-col">
        {organisation && (
          <MobileOrganisationHeader
            name={tenantName}
            logoUrl={tenantBrand?.logo_url}
            landing={tenantLanding}
            secondary={tenantBrand?.secondary_color}
            cover={tenantBrand?.cover}
          />
        )}
        <div className="flex justify-end px-6 pt-4">
          <LanguageSwitcher />
        </div>

        <div className="flex flex-1 items-center justify-center px-4 py-8">
          <div className="w-full max-w-lg">{children}</div>
        </div>
      </div>
    </div>
  );
}

function OrganisationPanel({
  name,
  logoUrl,
  landing,
  loading,
}: {
  name: string | null;
  logoUrl: string | null | undefined;
  landing: TenantLanding | null;
  loading: boolean;
}) {
  const { t } = useT();
  const features = [
    {
      icon: Clock,
      label: t("auth.org_feature_attendance", "Check in and out"),
    },
    {
      icon: CalendarDays,
      label: t("auth.org_feature_leave", "Request and track leave"),
    },
    {
      icon: Receipt,
      label: t("auth.org_feature_payslips", "See your payslips"),
    },
  ];
  // With its own headline the organisation's name moves above it as an
  // eyebrow; without one the name is the headline.
  const ownHeadline = Boolean(landing?.headline && name);

  return (
    <div className="flex w-full max-w-md flex-1 flex-col">
      <div className="flex flex-1 flex-col items-center justify-center text-center">
        <OrgMark
          name={name}
          logoUrl={logoUrl}
          className="h-20 w-20 rounded-2xl bg-primary-foreground/15 ring-0"
          initialClassName="text-3xl"
        />
        {ownHeadline && (
          <p className="mt-6 text-sm font-medium uppercase tracking-wider text-primary-foreground/70">
            {name}
          </p>
        )}
        <h2
          className={`${ownHeadline ? "mt-2" : "mt-8"} text-balance text-3xl font-bold leading-tight text-primary-foreground`}
        >
          {loading ? "…" : ((landing?.headline || name) ?? "")}
        </h2>
        <p className="mt-3 whitespace-pre-line text-base leading-relaxed text-primary-foreground/80">
          {landing?.message ||
            t(
              "auth.org_welcome",
              "Your workspace for attendance, leave and pay.",
            )}
        </p>

        <ul className="mt-10 w-full space-y-3 text-left">
          {features.map(({ icon: Icon, label }) => (
            <li
              key={label}
              className="flex items-center gap-3 rounded-xl bg-primary-foreground/10 px-4 py-3 text-sm text-primary-foreground backdrop-blur-sm"
            >
              <Icon
                className="h-4 w-4 shrink-0 opacity-80"
                aria-hidden="true"
              />
              {label}
            </li>
          ))}
        </ul>

        <SupportContacts landing={landing} />
      </div>

      <p className="mt-10 flex items-center justify-center gap-1.5 text-xs text-primary-foreground/70">
        <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
        {t("auth.powered_by", "Powered by ETHR")}
      </p>
    </div>
  );
}

/** Who to ask when signing in does not work: the organisation's HR desk. */
export function SupportContacts({
  landing,
  compact = false,
}: {
  landing: TenantLanding | null;
  compact?: boolean;
}) {
  const { t } = useT();
  const email = landing?.support_email;
  const phone = landing?.support_phone;
  if (!email && !phone) return null;

  const link =
    "inline-flex items-center gap-1.5 rounded-sm font-medium text-primary-foreground underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/60";

  return (
    <div
      className={
        compact
          ? "mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-primary-foreground/80"
          : "mt-8 w-full rounded-xl border border-primary-foreground/15 px-4 py-3 text-left text-sm text-primary-foreground/80"
      }
    >
      <span className="inline-flex items-center gap-1.5">
        <LifeBuoy className="h-3.5 w-3.5" aria-hidden="true" />
        {t("auth.org_help", "Trouble signing in? Contact HR")}
      </span>
      <span
        className={
          compact
            ? "contents"
            : "mt-2 flex flex-wrap items-center gap-x-5 gap-y-1"
        }
      >
        {email && (
          <a href={`mailto:${email}`} className={link}>
            <Mail className="h-3.5 w-3.5" aria-hidden="true" />
            {email}
          </a>
        )}
        {phone && (
          <a href={`tel:${phone.replace(/[^0-9+]/g, "")}`} className={link}>
            <Phone className="h-3.5 w-3.5" aria-hidden="true" />
            {phone}
          </a>
        )}
      </span>
    </div>
  );
}

/**
 * Phones never see the side panel, so an organisation's page opens with its
 * own band instead: logo, name, headline and the help contacts.
 */
function MobileOrganisationHeader({
  name,
  logoUrl,
  landing,
  secondary,
  cover,
}: {
  name: string | null;
  logoUrl: string | null | undefined;
  landing: TenantLanding | null;
  secondary: string | null | undefined;
  cover?: LandingCover | null;
}) {
  const { resolvedTheme } = useTheme();
  return (
    <div
      className="relative overflow-hidden bg-primary px-5 pb-5 pt-[max(1.25rem,env(safe-area-inset-top))] lg:hidden"
      style={
        resolvedTheme === "high-contrast"
          ? undefined
          : panelBackground(landing, secondary, cover)
      }
    >
      <div className="flex items-center gap-3">
        <OrgMark
          name={name}
          logoUrl={logoUrl}
          className="h-11 w-11 shrink-0 bg-primary-foreground/15 ring-0"
          initialClassName="text-lg"
        />
        <div className="min-w-0">
          <p className="truncate text-base font-semibold text-primary-foreground">
            {name ?? ""}
          </p>
          {landing?.headline && (
            <p className="line-clamp-2 text-sm text-primary-foreground/80">
              {landing.headline}
            </p>
          )}
        </div>
      </div>
      <SupportContacts landing={landing} compact />
    </div>
  );
}
