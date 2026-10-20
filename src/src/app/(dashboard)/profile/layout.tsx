"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  User,
  IdCard,
  Clock,
  FileText,
  ShieldCheck,
  Settings2,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { useMyProfile } from "@/features/profile/api";
import { hostContext } from "@/lib/auth/host-context";
import { useHost } from "@/lib/auth/host-provider";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * Tabs are routes rather than local state: /profile/security was already a
 * bookmarkable URL, and a self-service area people are sent links to ("update
 * your bank details") is worth deep-linking throughout.
 */
const TABS = [
  {
    href: "/profile",
    icon: User,
    key: "profile.tab.overview",
    label: "Overview",
  },
  {
    href: "/profile/personal",
    icon: IdCard,
    key: "profile.tab.personal",
    label: "Personal details",
  },
  {
    href: "/profile/requests",
    icon: Clock,
    key: "profile.tab.requests",
    label: "Change requests",
  },
  {
    href: "/profile/documents",
    icon: FileText,
    key: "profile.tab.documents",
    label: "Documents",
  },
  {
    href: "/profile/security",
    icon: ShieldCheck,
    key: "profile.tab.security",
    label: "Security",
  },
  {
    href: "/profile/preferences",
    icon: Settings2,
    key: "profile.tab.preferences",
    label: "Preferences",
  },
] as const;

export default function ProfileLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const { t } = useT();
  const pathname = usePathname();
  const { data: profile } = useMyProfile();
  const pendingCount = profile?.pending_updates?.length ?? 0;

  // The console host serves only account security from this area — the other
  // tabs are an organisation member's own records, and that host redirects
  // them to /admin/, so offering them led nowhere (console QA F22).
  const host = useHost();
  const rootDomain = process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || null;
  const onConsoleHost = hostContext(host, rootDomain) === "platform";
  const tabs = onConsoleHost
    ? TABS.filter((tab) => tab.href === "/profile/security")
    : TABS;
  // A static-export page loaded directly arrives with a trailing slash.
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, "") : pathname;

  // The dashboard layout's PageTitleBar already renders the title and breadcrumb
  // from route-meta, so this layout contributes only the tab strip.
  return (
    <div className="space-y-6">
      <nav
        aria-label={t("profile.tabs_label", "Profile sections")}
        className="-mx-1 flex gap-1 overflow-x-auto border-b border-border-default pb-px"
      >
        {tabs.map((tab) => {
          const active =
            tab.href === "/profile"
              ? path === "/profile"
              : path.startsWith(tab.href);
          const Icon = tab.icon;

          return (
            <Link
              key={tab.href}
              href={tab.href}
              aria-current={active ? "page" : undefined}
              className={cn(
                "flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors",
                active
                  ? "border-primary text-foreground"
                  : "border-transparent text-muted-foreground hover:text-foreground",
              )}
            >
              <Icon className="h-4 w-4" />
              {t(tab.key, tab.label)}
              {tab.href === "/profile/requests" && pendingCount > 0 && (
                <Badge className="bg-warning-soft text-warning-on-soft border-0 px-1.5 py-0 text-xs">
                  {pendingCount}
                </Badge>
              )}
            </Link>
          );
        })}
      </nav>

      {children}
    </div>
  );
}
