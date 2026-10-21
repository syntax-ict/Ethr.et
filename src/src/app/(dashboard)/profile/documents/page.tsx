"use client";

import { useState } from "react";
import { Download, FileText, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { EmptyState } from "@/components/shared/empty-state";
import {
  fetchMyDocumentLink,
  useMyDocuments,
  type MyDocument,
} from "@/features/profile/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { MyLettersCard } from "@/features/letters/components/my-letters-card";

/**
 * The documents HR has filed against the caller's own record. Read-only:
 * filing and removing stay with HR. Until 2026-10-20 an employee had no way
 * to see their own contract here.
 */
export default function ProfileDocumentsPage() {
  const { t } = useT();
  const query = useMyDocuments();

  return (
    <div className="space-y-6">
      <MyLettersCard />
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("profile.documents.title")}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <QueryBoundary
            query={query}
            isEmpty={(docs) => docs.length === 0}
            empty={
              <EmptyState
                icon={FileText}
                title={t("profile.documents.empty")}
                description={t("profile.documents.empty_hint")}
              />
            }
          >
            {(docs) => (
              <ul className="divide-y">
                {docs.map((doc) => (
                  <DocumentRow key={doc.public_id} doc={doc} />
                ))}
              </ul>
            )}
          </QueryBoundary>
        </CardContent>
      </Card>
    </div>
  );
}

function DocumentRow({ doc }: { doc: MyDocument }) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const [opening, setOpening] = useState(false);

  async function open() {
    setOpening(true);
    try {
      const url = await fetchMyDocumentLink(doc.public_id);
      window.open(url, "_blank", "noopener,noreferrer");
    } catch {
      toast.error(t("profile.documents.download_failed"));
    } finally {
      setOpening(false);
    }
  }

  return (
    <li className="flex flex-wrap items-center gap-3 py-3">
      <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">{doc.title}</p>
        <p className="text-xs text-muted-foreground">
          {t(`employee.documents.type_${doc.type}`, doc.type)}
          {doc.expiry_date
            ? ` · ${t("profile.documents.expires", undefined, {
                date: formatDate(doc.expiry_date),
              })}`
            : ""}
        </p>
      </div>
      {doc.is_expired && (
        <Badge variant="destructive">{t("profile.documents.expired")}</Badge>
      )}
      <Button
        variant="outline"
        size="sm"
        onClick={open}
        disabled={opening}
        aria-label={t("profile.documents.download_named", undefined, {
          title: doc.title,
        })}
      >
        {opening ? (
          <Loader2 className="mr-1 h-4 w-4 animate-spin" />
        ) : (
          <Download className="mr-1 h-4 w-4" />
        )}
        {t("profile.documents.download")}
      </Button>
    </li>
  );
}
