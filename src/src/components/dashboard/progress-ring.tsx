import { cn } from "@/lib/utils";

/**
 * One share of a whole as a ring, with the percentage in its middle: how
 * full the office is today. Plain SVG, like the sparkline, so the first
 * screen does not wait for the charting library.
 *
 * A progress bar to assistive technology, named by `label`. Only the filled
 * arc is coloured: an empty ring is a grey track with no "0%" in it, never
 * red, since nobody in yet at 07:00 is not bad news. The words beside the
 * ring say so instead.
 */
export function ProgressRing({
  value,
  label,
  color,
  className,
}: {
  /** 0 to 100; anything outside is clamped. */
  value: number;
  label: string;
  color: string;
  className?: string;
}) {
  const share = Math.max(0, Math.min(100, value));
  const size = 56;
  const stroke = 6;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  const shown = Math.round(share);

  return (
    <div
      role="progressbar"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={share}
      aria-valuetext={`${shown}%`}
      className={cn("relative h-14 w-14 shrink-0", className)}
    >
      <svg
        viewBox={`0 0 ${size} ${size}`}
        className="h-full w-full -rotate-90"
        aria-hidden="true"
        focusable="false"
      >
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="var(--border)"
          strokeWidth={stroke}
        />
        {share > 0 && (
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            fill="none"
            stroke={color}
            strokeWidth={stroke}
            strokeLinecap="round"
            strokeDasharray={circumference}
            strokeDashoffset={circumference * (1 - share / 100)}
            className="transition-[stroke-dashoffset] duration-500 motion-reduce:transition-none"
          />
        )}
      </svg>
      {share > 0 && (
        <span
          className="absolute inset-0 flex items-center justify-center text-xs font-semibold tabular-nums text-foreground"
          aria-hidden="true"
        >
          {shown}%
        </span>
      )}
    </div>
  );
}
