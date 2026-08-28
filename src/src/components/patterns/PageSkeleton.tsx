import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

/**
 * Route-segment loading skeletons.
 *
 * These render *inside* the dashboard shell — `loading.tsx` is the Suspense
 * fallback for a layout's `children`, so the real sidebar, header and title bar
 * are already on screen. A skeleton that redraws that chrome paints a second
 * ghost sidebar inside the content area, which is what the original
 * `(dashboard)/loading.tsx` did. Everything here is content-area only.
 *
 * Each variant mirrors the shape of the page it stands in for so the layout
 * does not jump when real data arrives (CLS). A generic spinner would be
 * cheaper to write and worse on every metric that matters.
 *
 * The whole region is `aria-hidden` with a polite live-region label: a screen
 * reader should hear "Loading", not read out two dozen empty placeholder boxes.
 */
function SkeletonRegion({
  label,
  className,
  children,
}: {
  label: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div
      role="status"
      aria-live="polite"
      aria-busy="true"
      className={className}
    >
      <span className="sr-only">{label}</span>
      <div aria-hidden="true" className="space-y-6">
        {children}
      </div>
    </div>
  );
}

/** Toolbar + data table. For index/list routes. */
export function ListPageSkeleton({
  rows = 8,
  columns = 5,
  label = "Loading",
}: {
  rows?: number;
  columns?: number;
  label?: string;
}) {
  return (
    <SkeletonRegion label={label}>
      {/* Filter/search toolbar */}
      <div className="flex flex-wrap items-center gap-3">
        <Skeleton className="h-9 w-full max-w-xs" />
        <Skeleton className="h-9 w-28" />
        <Skeleton className="h-9 w-28" />
        <Skeleton className="ml-auto h-9 w-32" />
      </div>

      <div className="overflow-hidden rounded-lg border border-border">
        {/* Header row reads slightly stronger than the body, as the real one does. */}
        <div className="flex items-center gap-4 border-b border-border bg-muted/50 px-4 py-3">
          {Array.from({ length: columns }).map((_, i) => (
            <Skeleton key={i} className="h-3.5 flex-1" />
          ))}
        </div>

        {Array.from({ length: rows }).map((_, r) => (
          <div
            key={r}
            className="flex items-center gap-4 border-b border-border px-4 py-3 last:border-b-0"
          >
            {Array.from({ length: columns }).map((_, c) => (
              <Skeleton
                key={c}
                className={cn("h-4 flex-1", c === 0 && "max-w-[40%]")}
              />
            ))}
          </div>
        ))}
      </div>
    </SkeletonRegion>
  );
}

/** KPI tiles above a chart. For dashboard/analytics/report routes. */
export function DashboardPageSkeleton({
  tiles = 4,
  label = "Loading",
}: {
  tiles?: number;
  label?: string;
}) {
  return (
    <SkeletonRegion label={label}>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: tiles }).map((_, i) => (
          <div
            key={i}
            className="space-y-3 rounded-lg border border-border bg-card p-4"
          >
            <Skeleton className="h-3.5 w-24" />
            <Skeleton className="h-7 w-20" />
            <Skeleton className="h-3 w-16" />
          </div>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="rounded-lg border border-border bg-card p-4 lg:col-span-2">
          <Skeleton className="h-4 w-40" />
          <Skeleton className="mt-4 h-56 w-full" />
        </div>
        <div className="space-y-4 rounded-lg border border-border bg-card p-4">
          <Skeleton className="h-4 w-28" />
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="flex items-center gap-3">
              <Skeleton className="h-8 w-8 rounded-full" />
              <div className="flex-1 space-y-1.5">
                <Skeleton className="h-3.5 w-3/4" />
                <Skeleton className="h-3 w-1/2" />
              </div>
            </div>
          ))}
        </div>
      </div>
    </SkeletonRegion>
  );
}

/** Header block + two-column cards. For `[id]` detail routes. */
export function DetailPageSkeleton({ label = "Loading" }: { label?: string }) {
  return (
    <SkeletonRegion label={label}>
      <div className="flex flex-wrap items-center gap-4">
        <Skeleton className="h-16 w-16 rounded-full" />
        <div className="space-y-2">
          <Skeleton className="h-6 w-48" />
          <Skeleton className="h-3.5 w-32" />
        </div>
        <div className="ml-auto flex gap-2">
          <Skeleton className="h-9 w-24" />
          <Skeleton className="h-9 w-24" />
        </div>
      </div>

      <div className="flex gap-2 border-b border-border pb-px">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-8 w-24" />
        ))}
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {Array.from({ length: 2 }).map((_, card) => (
          <div
            key={card}
            className="space-y-4 rounded-lg border border-border bg-card p-4"
          >
            <Skeleton className="h-4 w-36" />
            {Array.from({ length: 5 }).map((_, row) => (
              <div key={row} className="flex justify-between gap-4">
                <Skeleton className="h-3.5 w-28" />
                <Skeleton className="h-3.5 w-40" />
              </div>
            ))}
          </div>
        ))}
      </div>
    </SkeletonRegion>
  );
}

/** Stacked labelled fields. For create/edit routes. */
export function FormPageSkeleton({
  fields = 6,
  columns = 2,
  label = "Loading",
}: {
  fields?: number;
  columns?: number;
  label?: string;
}) {
  return (
    <SkeletonRegion label={label}>
      <div
        className={cn(
          "grid gap-6",
          columns === 2 ? "lg:grid-cols-2" : "lg:grid-cols-1",
        )}
      >
        {Array.from({ length: columns }).map((_, card) => (
          <div
            key={card}
            className="space-y-4 rounded-lg border border-border bg-card p-4"
          >
            <Skeleton className="h-4 w-40" />
            {Array.from({ length: fields }).map((_, i) => (
              <div key={i} className="space-y-1.5">
                <Skeleton className="h-3.5 w-24" />
                <Skeleton className="h-9 w-full" />
              </div>
            ))}
          </div>
        ))}
      </div>

      <div className="flex justify-end gap-3">
        <Skeleton className="h-9 w-24" />
        <Skeleton className="h-9 w-32" />
      </div>
    </SkeletonRegion>
  );
}

/** Settings-style stacked panels. */
export function SettingsPageSkeleton({
  panels = 3,
  label = "Loading",
}: {
  panels?: number;
  label?: string;
}) {
  return (
    <SkeletonRegion label={label}>
      {Array.from({ length: panels }).map((_, i) => (
        <div
          key={i}
          className="space-y-4 rounded-lg border border-border bg-card p-4"
        >
          <div className="space-y-1.5">
            <Skeleton className="h-4 w-44" />
            <Skeleton className="h-3 w-64" />
          </div>
          {Array.from({ length: 3 }).map((_, row) => (
            <div
              key={row}
              className="flex items-center justify-between gap-4 border-t border-border pt-4"
            >
              <div className="space-y-1.5">
                <Skeleton className="h-3.5 w-36" />
                <Skeleton className="h-3 w-52" />
              </div>
              <Skeleton className="h-6 w-11 rounded-full" />
            </div>
          ))}
        </div>
      ))}
    </SkeletonRegion>
  );
}
