"use client";

import Link from "next/link";
import { ExternalLink } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageActions } from "@/components/shared/page-actions";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useAdminMarketing } from "@/features/admin/api";
import { SectionCard } from "@/features/admin/marketing/section-card";
import { useT } from "@/lib/i18n/useT";

/**
 * Super-admin editor for the public landing page.
 *
 * Every heading and row on www.ethr.et used to be a translation key read by
 * a hard-coded array, so a wrong sentence was nobody's job to correct and a
 * right one needed a deploy. The page's anatomy is still code (which
 * sections exist and how each is drawn); what is edited here is every word
 * in both languages, the order and visibility of rows, and the pictures.
 * The server refuses the claims the capability audit took off the site, so
 * a refused sentence comes back beside its field.
 */
export default function AdminMarketingPage() {
  const { t, locale } = useT();
  const query = useAdminMarketing();
  const liveHref = locale === "am" ? "/am" : "/en";

  return (
    <RoleGate minRole="super_admin">
      <div className="space-y-6">
        <PageActions
          actions={
            <Button variant="outline" asChild>
              <Link href={liveHref} target="_blank" rel="noreferrer">
                <ExternalLink className="mr-2 h-4 w-4" aria-hidden="true" />
                {t("admin_marketing.view_live", "View live page")}
              </Link>
            </Button>
          }
        />

        <div
          role="note"
          className="rounded-xl border border-border/60 bg-muted/40 p-4 text-sm leading-relaxed text-muted-foreground"
        >
          {t(
            "admin_marketing.note",
            "A saved change reaches the public page within a minute. The next release carries it into the page a search engine reads. Claims the site may not make are refused with a message beside the field.",
          )}
        </div>

        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-96 w-full" />}
          isEmpty={(content) => content.sections.length === 0}
          empty={
            <p className="text-sm text-muted-foreground">
              {t(
                "admin_marketing.empty",
                "No sections yet. Run the seeder to create the first-run content.",
              )}
            </p>
          }
        >
          {(content) => (
            <div className="space-y-6">
              {content.sections.map((section) => (
                <SectionCard
                  key={section.public_id}
                  section={section}
                  icons={content.icons}
                />
              ))}
            </div>
          )}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}
