"use client";

import { useEffect } from "react";
import Link from "next/link";
import {
  CalendarDays,
  CheckCircle2,
  Clock,
  Receipt,
  ShieldCheck,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { LanguageSwitcher } from "@/components/shared/language-switcher";
import { ProductStoryAnimation } from "@/components/shared/product-story-animation";
import { OrgMark } from "@/components/shared/org-mark";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { applyBrandToDocument } from "@/features/branding/TenantBrandingProvider";

const taglineKeys = [
  "layout_multi_tenant",
  "layout_offline",
  "layout_tax",
  "layout_bilingual",
] as const;

/**
 * The shared sign-in layout. One design for every organisation: on an
 * organisation's own address - ethr.et/{slug}, its subdomain or its domain -
 * the brand panel carries that organisation's logo, name and colours, and
 * ETHR steps back to "Powered by ETHR". On the generic sign-in it is ETHR's.
 */
export function AuthLayoutClient({ children }: { children: React.ReactNode }) {
  const { t } = useT();
  const { context, tenantName, tenantBrand, tenantContextLoading } =
    useAuthHostContext();
  const organisation = context === "tenant";

  // The colours go on the document so every control on the page - the
  // button, focus rings, links - takes them, as they do once signed in.
  const primary = tenantBrand?.primary_color ?? null;
  const accent = tenantBrand?.accent_color ?? null;
  useEffect(() => {
    if (!organisation) return;
    return applyBrandToDocument({
      primary_color: primary,
      accent_color: accent,
    });
  }, [organisation, primary, accent]);

  // The tab names the organisation, as its own page should.
  useEffect(() => {
    if (!organisation || !tenantName) return;
    const previous = document.title;
    document.title = `${t("auth.sign_in", "Sign In")} · ${tenantName}`;
    return () => {
      document.title = previous;
    };
  }, [organisation, tenantName, t]);

  return (
    <div className="flex min-h-screen">
      {/* Left panel — brand */}
      <div className="relative hidden w-1/2 overflow-hidden bg-primary lg:flex lg:flex-col">
        {/* Subtle grid pattern overlay */}
        <div className="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:40px_40px]" />
        <div className="absolute bottom-0 left-0 h-1/3 w-full bg-gradient-to-t from-black/10 to-transparent" />

        <div className="relative flex flex-1 flex-col items-center justify-center overflow-y-auto p-12">
          {organisation ? (
            <OrganisationPanel
              name={tenantName}
              logoUrl={tenantBrand?.logo_url}
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
                  "Manage employees, attendance, payroll, and leave — all in one platform built for Ethiopian organizations.",
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
  loading,
}: {
  name: string | null;
  logoUrl: string | null | undefined;
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

  return (
    <div className="flex w-full max-w-md flex-1 flex-col">
      <div className="flex flex-1 flex-col items-center justify-center text-center">
        <OrgMark
          name={name}
          logoUrl={logoUrl}
          className="h-20 w-20 rounded-2xl bg-primary-foreground/15 ring-0"
          initialClassName="text-3xl"
        />
        <h2 className="mt-8 text-3xl font-bold leading-tight text-primary-foreground">
          {loading ? "…" : (name ?? "")}
        </h2>
        <p className="mt-3 text-base leading-relaxed text-primary-foreground/75">
          {t(
            "auth.org_welcome",
            "Your workspace for attendance, leave and pay.",
          )}
        </p>

        <ul className="mt-10 w-full space-y-3 text-left">
          {features.map(({ icon: Icon, label }) => (
            <li
              key={label}
              className="flex items-center gap-3 rounded-xl bg-primary-foreground/10 px-4 py-3 text-sm text-primary-foreground"
            >
              <Icon
                className="h-4 w-4 shrink-0 opacity-80"
                aria-hidden="true"
              />
              {label}
            </li>
          ))}
        </ul>
      </div>

      <p className="mt-10 flex items-center justify-center gap-1.5 text-xs text-primary-foreground/70">
        <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
        {t("auth.powered_by", "Powered by ETHR")}
      </p>
    </div>
  );
}
