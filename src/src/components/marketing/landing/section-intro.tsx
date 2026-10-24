import { cn } from "@/lib/utils";

/**
 * A section's heading and the sentence under it, left-aligned, with no
 * label above and no number beside: a heading says what the section is,
 * and anything placed above it would only repeat that. Numbers are kept
 * for the one section that is a sequence.
 */
export function SectionIntro({
  id,
  heading,
  subheading,
  tone = "default",
  className,
}: {
  id: string;
  heading: string;
  subheading?: string | null;
  tone?: "default" | "inverse";
  className?: string;
}) {
  return (
    <div className={cn("max-w-2xl", className)}>
      <h2
        id={id}
        className={cn(
          "text-3xl font-semibold leading-tight tracking-[-0.015em] sm:text-4xl",
          tone === "inverse" ? "text-sidebar-foreground" : "text-foreground",
        )}
      >
        {heading}
      </h2>
      {subheading && (
        <p
          className={cn(
            "mt-4 text-lg leading-relaxed",
            tone === "inverse"
              ? "text-sidebar-foreground/75"
              : "text-muted-foreground",
          )}
        >
          {subheading}
        </p>
      )}
    </div>
  );
}
