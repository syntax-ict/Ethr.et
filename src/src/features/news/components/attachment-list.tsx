"use client";

import { FileText } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { fileSize } from "../news-format";

export interface StoryFile {
  id: string;
  name: string;
  size: number;
  /** Null where nothing may be downloaded by address (the app, previews). */
  url: string | null;
}

/**
 * A story's documents, drawn once for the article, the reading pane, the
 * preview and the signed-in list.
 *
 * How a document opens: through `onDownload` when the caller gives one (in
 * the app a signed-in reader fetches it through the API as a blob, because
 * the organisation travels in `X-Tenant`, which a link cannot send), else a
 * plain link where the file has a public `url`, else nothing to press (the
 * editor's preview). The handler wins over the address, so the app never
 * depends on the API leaving `url` empty.
 *
 * `rows` is the article's full-width list; `chips` is the signed-in list's
 * wrapping row of small buttons.
 */
export function AttachmentList({
  files,
  onDownload,
  variant = "rows",
  className,
}: {
  files: StoryFile[];
  onDownload?: (file: { id: string; name: string }) => void;
  variant?: "rows" | "chips";
  className?: string;
}) {
  const { t } = useT();
  const chips = variant === "chips";

  return (
    <ul
      className={cn(
        chips
          ? "flex flex-wrap gap-2"
          : "divide-y divide-border overflow-hidden rounded-xl border border-border",
        className,
      )}
    >
      {files.map((file) => {
        const body = (
          <>
            <FileText
              className={cn(
                "shrink-0 text-primary",
                chips ? "h-3.5 w-3.5" : "h-5 w-5",
              )}
              aria-hidden="true"
            />
            <span
              className={cn(
                "min-w-0 truncate font-medium text-foreground",
                chips ? "text-xs" : "flex-1 text-sm",
              )}
            >
              {file.name}
            </span>
            <span className="shrink-0 text-xs text-muted-foreground">
              {chips
                ? fileSize(file.size)
                : t("news.file_meta", "PDF, :size", {
                    size: fileSize(file.size),
                  })}
            </span>
          </>
        );
        const download = (
          <span className="sr-only"> {t("news.download", "Download")}</span>
        );
        const control = chips
          ? "inline-flex min-h-9 max-w-full items-center gap-1.5 rounded-md border border-border px-2.5 py-1 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          : "flex min-h-11 w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring";
        return (
          <li key={file.id}>
            {onDownload ? (
              <button
                type="button"
                onClick={() => onDownload(file)}
                className={control}
              >
                {body}
                {download}
              </button>
            ) : file.url ? (
              <a href={file.url} download={file.name} className={control}>
                {body}
                {download}
              </a>
            ) : (
              <div className="flex min-h-11 items-center gap-3 px-4 py-2.5">
                {body}
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}
