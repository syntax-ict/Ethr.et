"use client";

import { useT } from "@/lib/i18n/useT";
import { useTrustedBy } from "@/features/marketing/api";
import { cn } from "@/lib/utils";

/**
 * "Trusted by": the organisations ETHR may name on its public site.
 *
 * Every name here is a claim, held to the same rule as the testimonial: an
 * organisation appears only after its administrator agreed to be named, while
 * it is an active customer, and unless the platform hid it
 * (`Tenant::scopeShowcased`). With nobody to name, the section is not drawn
 * at all, rather than a heading over an empty row.
 */
export function TrustedBy() {
  const { t } = useT();
  const organisations = useTrustedBy();

  if (organisations.length === 0) return null;

  return (
    <section
      aria-labelledby="trusted-by-heading"
      className="border-b border-border/50 py-12 sm:py-14"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h2
          id="trusted-by-heading"
          className="text-center text-sm font-semibold uppercase tracking-wider text-muted-foreground"
        >
          {t("marketing.trusted_by.title", "Trusted by")}
        </h2>
        <ul
          className={cn(
            "mt-8 flex flex-wrap items-center justify-center gap-x-10 gap-y-8",
          )}
        >
          {organisations.map((org) => (
            <li key={org.public_id}>
              <a
                href={org.url}
                className="group flex h-14 items-center justify-center rounded-md px-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                title={org.name}
              >
                {org.logo_url ? (
                  // Served by ETHR, re-encoded on upload; next/image adds
                  // nothing to a static export.
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    src={org.logo_url}
                    alt={org.name}
                    loading="lazy"
                    className="max-h-12 w-auto max-w-40 object-contain opacity-70 grayscale transition duration-200 group-hover:opacity-100 group-hover:grayscale-0"
                  />
                ) : (
                  <span className="text-lg font-semibold tracking-tight text-muted-foreground transition-colors group-hover:text-foreground">
                    {org.name}
                  </span>
                )}
              </a>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
