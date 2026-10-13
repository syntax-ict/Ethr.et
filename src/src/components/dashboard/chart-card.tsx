import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

/**
 * The frame every dashboard chart and panel sits in: a title, an optional
 * one-line description, an action slot on the right (a range picker, a "View
 * all" link), then the content. One header size for every panel, where the
 * dashboards used `text-sm`, `text-base` and `text-base` with an icon for the
 * same role, sometimes on one page.
 */
export function ChartCard({
  title,
  description,
  action,
  footer,
  children,
  className,
  contentClassName,
  titleId,
}: {
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  footer?: ReactNode;
  children: ReactNode;
  className?: string;
  contentClassName?: string;
  titleId?: string;
}) {
  return (
    <section
      aria-labelledby={titleId}
      className={cn(
        "flex min-w-0 flex-col rounded-xl border border-border bg-card text-card-foreground shadow-xs",
        className,
      )}
    >
      <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-5 pt-5">
        <div className="min-w-0">
          <h3
            id={titleId}
            className="text-sm font-semibold leading-6 tracking-tight text-foreground"
          >
            {title}
          </h3>
          {description && (
            <p className="text-xs text-muted-foreground">{description}</p>
          )}
        </div>
        {action && (
          <div className="flex shrink-0 items-center gap-2">{action}</div>
        )}
      </header>
      <div className={cn("min-w-0 flex-1 px-5 pb-5 pt-4", contentClassName)}>
        {children}
      </div>
      {footer && (
        <footer className="border-t border-border px-5 py-3 text-xs text-muted-foreground">
          {footer}
        </footer>
      )}
    </section>
  );
}

/**
 * A labelled group of tiles or panels. The heading gives the eye an entry
 * point per group instead of a run of undifferentiated cards.
 */
export function DashboardSection({
  id,
  title,
  description,
  action,
  children,
  className,
}: {
  id: string;
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section aria-labelledby={id} className={cn("space-y-3", className)}>
      <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <div className="min-w-0">
          <h2
            id={id}
            className="text-base font-semibold tracking-tight text-foreground"
          >
            {title}
          </h2>
          {description && (
            <p className="text-sm text-muted-foreground">{description}</p>
          )}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}
