"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page?: number;
  total?: number;
}

/**
 * Prev/next controls for the lists that render a raw `<table>` rather than
 * `DataTable` (which has its own built-in pagination).
 *
 * Several such lists held `const [page, setPage] = useState(1)`, passed `page`
 * to the query, and then never rendered anything that could call `setPage` —
 * so every record past the first page was unreachable. ESLint surfaced them as
 * "'setPage' is assigned a value but never used".
 *
 * Renders nothing for a single page, so it can be dropped in unconditionally.
 */
export function PaginationControls({
  meta,
  onPageChange,
  disabled = false,
}: {
  meta: PaginationMeta | undefined;
  onPageChange: (page: number) => void;
  disabled?: boolean;
}) {
  const { t } = useT();

  if (!meta || meta.last_page <= 1) return null;

  const { current_page: current, last_page: last } = meta;

  return (
    <nav
      aria-label={t("common.pagination", "Pagination")}
      className="flex items-center justify-between gap-4 border-t border-border-default px-4 py-3"
    >
      <p className="text-xs text-muted-foreground">
        {t("common.page_of", "Page {current} of {last}")
          .replace("{current}", String(current))
          .replace("{last}", String(last))}
      </p>
      <div className="flex gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={disabled || current <= 1}
          onClick={() => onPageChange(current - 1)}
        >
          <ChevronLeft className="mr-1 h-3 w-3" />
          {t("common.previous", "Previous")}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={disabled || current >= last}
          onClick={() => onPageChange(current + 1)}
        >
          {t("common.next", "Next")}
          <ChevronRight className="ml-1 h-3 w-3" />
        </Button>
      </div>
    </nav>
  );
}
