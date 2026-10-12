import { useId } from "react";
import { cn } from "@/lib/utils";

/**
 * A trend line with no axes, for a KPI tile.
 *
 * Plain SVG rather than recharts: a dashboard draws several of these above the
 * fold, and recharts (~435 KB) is deliberately split out of the first load.
 * Decorative — the figure beside it carries the meaning — so it is hidden from
 * assistive technology.
 */
export function Sparkline({
  values,
  color,
  className,
}: {
  values: number[];
  color: string;
  className?: string;
}) {
  const id = useId();
  const width = 96;
  const height = 32;
  const pad = 2;

  const min = Math.min(...values);
  const max = Math.max(...values);
  const span = max - min || 1;
  const step = (width - pad * 2) / (values.length - 1);

  const points = values.map((v, i) => [
    pad + i * step,
    pad + (height - pad * 2) * (1 - (v - min) / span),
  ]);
  const line = points
    .map(([x, y], i) => `${i === 0 ? "M" : "L"}${x.toFixed(1)},${y.toFixed(1)}`)
    .join(" ");
  const area = `${line} L${points[points.length - 1][0].toFixed(1)},${height} L${points[0][0].toFixed(1)},${height} Z`;
  const gradient = `spark-${id.replace(/:/g, "")}`;

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn("overflow-visible", className)}
      aria-hidden="true"
      focusable="false"
    >
      <defs>
        <linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity={0.25} />
          <stop offset="100%" stopColor={color} stopOpacity={0} />
        </linearGradient>
      </defs>
      <path d={area} fill={`url(#${gradient})`} />
      <path
        d={line}
        fill="none"
        stroke={color}
        strokeWidth={1.75}
        strokeLinecap="round"
        strokeLinejoin="round"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  );
}
