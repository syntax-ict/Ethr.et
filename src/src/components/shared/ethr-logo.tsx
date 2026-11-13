"use client";

import { useState } from "react";
import { cn } from "@/lib/utils";

/**
 * The ETHR mark: a white lowercase e, open at the lower right, with a Gold dot
 * at its baseline, on the brand blue tile. Drawn from the master geometry in
 * `docs/brand/ethr-symbol.svg`; change the master and this together.
 *
 * `small` is the variant for renders of 24px and under: heavier strokes and a
 * larger dot, so the e does not thin out at that size.
 *
 * The colours are tokens that hold the same value in every theme, because the
 * mark is a fixed identity and must not follow light, dark or high contrast.
 * The size comes from `className`.
 */
export function EthrSymbol({
  className,
  small,
}: {
  className?: string;
  small?: boolean;
}) {
  const stroke = small ? 7.5 : 6.5;

  return (
    <svg
      viewBox="0 0 64 64"
      aria-hidden="true"
      focusable="false"
      className={cn("shrink-0 rounded-[23.4375%]", className)}
    >
      <rect width="64" height="64" rx="15" fill="var(--brand-mark-tile)" />
      <path
        d="M15 32H41"
        stroke="var(--brand-mark-glyph)"
        strokeWidth={stroke}
        fill="none"
      />
      <path
        d={
          small
            ? "M40.41 35.87A13 13 0 1 0 37.96 40.36"
            : "M40.59 35.26A13 13 0 1 0 37.96 40.36"
        }
        stroke="var(--brand-mark-glyph)"
        strokeWidth={stroke}
        fill="none"
      />
      <circle
        cx="48"
        cy="43.5"
        r={small ? 5 : 4.2}
        fill="var(--brand-mark-dot)"
      />
    </svg>
  );
}

/**
 * The drawn "ethr" wordmark with its Gold dot. The letters take the current
 * text colour so the header follows the theme; the dot is the fixed mark colour.
 * Never retype it in a font.
 */
export function EthrWordmark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 -14 122.5 46.5"
      role="img"
      aria-label="ETHR"
      className={cn("shrink-0", className)}
    >
      <g fill="none" stroke="currentColor" strokeWidth="6.5">
        <path d="M3.25 16.25H29.25M28.84 19.51A13 13 0 1 0 26.21 24.61" />
        <path d="M41.75 -8V32.5M36 3.25H52" />
        <path d="M59.25 -14V32.5M59.25 16.25A13 13 0 0 1 85.25 16.25V32.5" />
        <path d="M97.75 0V32.5M97.75 16.25A13 13 0 0 1 110.75 3.25" />
      </g>
      <circle cx="118" cy="27.75" r="4.2" fill="var(--brand-mark-dot)" />
    </svg>
  );
}

/**
 * ETHR's own logo tile: the platform logo the operator set in the console when
 * there is one that loads, otherwise the ETHR mark (`EthrSymbol`).
 *
 * An operator's logo sits on a white tile for the reason `OrgMark` gives: it
 * was drawn for a light page, and the tile makes it read on any surface,
 * including the sidebar, which is dark in both themes.
 */
export function EthrLogoTile({
  logoUrl,
  className,
  small,
}: {
  logoUrl?: string | null;
  className?: string;
  small?: boolean;
}) {
  const [broken, setBroken] = useState(false);

  if (logoUrl && !broken) {
    return (
      <span
        aria-hidden="true"
        className={cn(
          "flex shrink-0 items-center justify-center overflow-hidden rounded-md bg-white p-0.5",
          className,
        )}
      >
        {/* Operator-supplied and arbitrary, so it cannot be in next.config's
            remotePatterns; the call marketing-footer.tsx makes for this URL. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={logoUrl}
          alt=""
          className="h-full w-full object-contain"
          onError={() => setBroken(true)}
          referrerPolicy="no-referrer"
        />
      </span>
    );
  }

  return <EthrSymbol className={className} small={small} />;
}
