"use client";

import { useT } from "@/lib/i18n/useT";
import { paragraphs, segments } from "../news-format";

/**
 * Text as paragraphs with its https links live: the one place a story's body
 * and an FAQ answer are drawn.
 *
 * Text only: paragraphs come from blank lines and links from https
 * addresses, and everything else is rendered as text (React escapes it), so
 * nothing the text contains can become markup. Links open in a new tab and
 * carry no referrer and no endorsement (`nofollow ugc`).
 *
 * `className` is the block's own spacing and type size, which differ between
 * an article and an FAQ answer.
 */
export function PlainText({
  text,
  className,
}: {
  text: string;
  className?: string;
}) {
  const { t } = useT();

  return (
    <div className={className}>
      {paragraphs(text).map((paragraph, i) => (
        <p key={i} className="whitespace-pre-line">
          {segments(paragraph).map((segment, j) =>
            segment.kind === "link" ? (
              <a
                key={j}
                href={segment.href}
                target="_blank"
                rel="noopener noreferrer nofollow ugc"
                className="break-words text-primary underline underline-offset-4 hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {segment.href}
                <span className="sr-only">
                  {" "}
                  {t("news.opens_new_tab", "(opens in a new tab)")}
                </span>
              </a>
            ) : (
              <span key={j}>{segment.text}</span>
            ),
          )}
        </p>
      ))}
    </div>
  );
}
