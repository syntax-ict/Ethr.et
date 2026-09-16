"use client";

import { AlertTriangle, Languages } from "lucide-react";
import {
  LEGAL_REVIEW_REQUIRED,
  type LegalDocument,
} from "@/lib/legal/documents";
import { formatDateOnly } from "@/lib/utils/date";
import { useT } from "@/lib/i18n/useT";

/**
 * Renders a legal document: privacy policy, terms of service.
 *
 * The prose comes from `lib/legal/documents.ts` rather than the i18n
 * dictionaries — see that file for why. The chrome around it does go through
 * `useT`, so a reader on the Amharic site gets an Amharic frame and an explicit
 * Amharic explanation of why the body is not, rather than silent English.
 *
 * `formatDateOnly` formats the effective date rather than the string being
 * written out longhand: it is a calendar date, and rendering one through a
 * timezone-aware formatter is what once turned an invoice due date of
 * "2026-10-01" into "30 Sept 2026" for anyone behind UTC.
 */
export function LegalDocumentView({ document }: { document: LegalDocument }) {
  const { t, locale } = useT();

  // English is the only translation that exists. When one is added to
  // `documents.ts`, this notice should key off that rather than off the locale.
  const showTranslationNotice = locale !== "en";

  return (
    <div>
      {/* Header */}
      <section className="border-b border-border/50 bg-muted/20">
        <div className="mx-auto max-w-3xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
          <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
            {document.title}
          </h1>
          <p className="mt-4 text-lg leading-relaxed text-muted-foreground">
            {document.summary}
          </p>
          <p className="mt-6 text-sm text-muted-foreground">
            {t("marketing.legal.effective", "In effect from")}{" "}
            <time dateTime={document.effectiveDate} className="font-medium">
              {formatDateOnly(document.effectiveDate)}
            </time>
          </p>
        </div>
      </section>

      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        {/* Unreviewed-draft banner. Removing this is a deliberate act — see
            LEGAL_REVIEW_REQUIRED. It sits above the contents so it cannot be
            scrolled past. */}
        {LEGAL_REVIEW_REQUIRED && (
          <div
            role="note"
            className="mb-10 flex items-start gap-3 rounded-xl border border-status-warning/30 bg-status-warning/5 p-4"
          >
            <AlertTriangle
              className="mt-0.5 h-5 w-5 shrink-0 text-status-warning"
              aria-hidden="true"
            />
            <div className="text-sm leading-relaxed">
              <p className="font-semibold text-foreground">
                {t(
                  "marketing.legal.draft_title",
                  "Draft — pending legal review",
                )}
              </p>
              <p className="mt-1 text-muted-foreground">
                {t(
                  "marketing.legal.draft_body",
                  "This document is an accurate description of how the software behaves, but it has not yet been reviewed by a qualified legal adviser and should not be relied upon as a binding agreement.",
                )}
              </p>
            </div>
          </div>
        )}

        {showTranslationNotice && (
          <div
            role="note"
            className="mb-10 flex items-start gap-3 rounded-xl border border-border/60 bg-muted/40 p-4"
          >
            <Languages
              className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground"
              aria-hidden="true"
            />
            <p className="text-sm leading-relaxed text-muted-foreground">
              {t(
                "marketing.legal.english_only",
                "This document is currently available in English only. A translation is being prepared; until it is published, the English text is the operative version.",
              )}
            </p>
          </div>
        )}

        {/* Contents. A legal document people are expected to actually read
            needs a way in; anchors also give support a way to cite a clause. */}
        <nav
          aria-label={t("marketing.legal.contents", "Contents")}
          className="mb-12 rounded-xl border border-border/60 bg-card p-5"
        >
          <h2 className="text-sm font-semibold text-foreground">
            {t("marketing.legal.contents", "Contents")}
          </h2>
          <ol className="mt-3 space-y-2">
            {document.sections.map((section, i) => (
              <li key={section.id} className="text-sm">
                <a
                  href={`#${section.id}`}
                  className="text-muted-foreground underline-offset-4 transition-colors hover:text-primary hover:underline"
                >
                  <span className="tabular-nums">{i + 1}.</span>{" "}
                  {section.heading}
                </a>
              </li>
            ))}
          </ol>
        </nav>

        <div className="space-y-12">
          {document.sections.map((section, i) => (
            <section key={section.id} id={section.id} className="scroll-mt-24">
              <h2 className="text-xl font-semibold tracking-tight text-foreground">
                <span className="tabular-nums text-muted-foreground">
                  {i + 1}.
                </span>{" "}
                {section.heading}
              </h2>

              <div className="mt-4 space-y-4">
                {section.blocks.map((block, bi) =>
                  block.list ? (
                    <ul key={bi} className="space-y-2.5">
                      {block.list.map((item) => (
                        <li
                          key={item}
                          className="flex gap-3 text-sm leading-relaxed text-muted-foreground"
                        >
                          <span
                            className="mt-2 h-1 w-1 shrink-0 rounded-full bg-border-strong"
                            aria-hidden="true"
                          />
                          <span>{item}</span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p
                      key={bi}
                      className="text-sm leading-relaxed text-muted-foreground"
                    >
                      {block.text}
                    </p>
                  ),
                )}
              </div>
            </section>
          ))}
        </div>
      </div>
    </div>
  );
}
