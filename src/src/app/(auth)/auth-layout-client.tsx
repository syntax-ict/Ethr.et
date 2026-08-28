"use client";

import Link from "next/link";
import { CheckCircle2 } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { LanguageSwitcher } from "@/components/shared/language-switcher";
import { ProductStoryAnimation } from "@/components/shared/product-story-animation";

const taglineKeys = [
  "layout_multi_tenant",
  "layout_offline",
  "layout_tax",
  "layout_bilingual",
] as const;

export function AuthLayoutClient({ children }: { children: React.ReactNode }) {
  const { t } = useT();

  return (
    <div className="flex min-h-screen">
      {/* Left panel — brand */}
      <div className="relative hidden w-1/2 overflow-hidden bg-primary lg:flex lg:flex-col">
        {/* Subtle grid pattern overlay */}
        <div className="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:40px_40px]" />
        <div className="absolute bottom-0 left-0 h-1/3 w-full bg-gradient-to-t from-black/10 to-transparent" />

        <div className="relative flex flex-1 flex-col items-center justify-center overflow-y-auto p-12">
          <div className="max-w-md text-center">
            <Link href="/" className="inline-flex items-center gap-2.5">
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/20 backdrop-blur-sm">
                <span className="text-xl font-bold text-white">E</span>
              </div>
            </Link>
            <h2 className="mt-8 text-3xl font-bold leading-tight text-white">
              {t("auth.layout_title", "Ethiopian Workforce Operating System")}
            </h2>
            <p className="mt-4 text-base leading-relaxed text-white/75">
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
                    className="h-3.5 w-3.5 shrink-0 text-white/50"
                    aria-hidden="true"
                  />
                  <span className="text-xs text-white/70">
                    {t(`auth.${key}`)}
                  </span>
                </div>
              ))}
            </div>
          </div>
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
