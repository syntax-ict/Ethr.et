"use client";

import { cn } from "@/lib/utils";

/**
 * The pieces the animated stories are drawn from: things that are on screen
 * at some steps and not others (`Part`), that travel (`Move`), that draw
 * themselves in (`Draw`) or fill (`Grow`), and the small vocabulary of
 * people, phones, sheets, pins, ticks and crosses they share. Each story is
 * a pure function of a step number; `globals.css` says how a part moves
 * when its step comes and goes.
 */

export type From = "fade" | "up" | "down" | "left" | "right" | "pop" | "drop";

/** Something that is on screen at some steps and not at others. */
export function Part({
  on,
  from = "fade",
  delay = 0,
  children,
}: {
  on: boolean;
  from?: From;
  delay?: number;
  children: React.ReactNode;
}) {
  return (
    <g
      className="ethr-story-part"
      data-on={on}
      data-from={from}
      style={on && delay ? { transitionDelay: `${delay}ms` } : undefined}
    >
      {children}
    </g>
  );
}

/**
 * Something that travels: its offset from where it is drawn. The scale is
 * about the drawing's origin (0, 0), so a caller that shrinks something
 * while moving it works out the offset with that in mind.
 */
export function Move({
  x = 0,
  y = 0,
  scale = 1,
  delay = 0,
  children,
}: {
  x?: number;
  y?: number;
  scale?: number;
  delay?: number;
  children: React.ReactNode;
}) {
  return (
    <g
      className="ethr-story-move"
      style={{
        transform: `translate(${x}px, ${y}px) scale(${scale})`,
        transitionDelay: delay ? `${delay}ms` : undefined,
      }}
    >
      {children}
    </g>
  );
}

/** A stroke that draws itself in, like a pen or a trail. */
export function Draw({
  on,
  d,
  className,
  width = 3,
  delay = 0,
}: {
  on: boolean;
  d: string;
  className: string;
  width?: number;
  delay?: number;
}) {
  return (
    <path
      d={d}
      pathLength={1}
      fill="none"
      strokeWidth={width}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={cn("ethr-story-draw", className)}
      style={{
        strokeDasharray: 1,
        strokeDashoffset: on ? 0 : 1,
        opacity: on ? 1 : 0,
        transitionDelay: on && delay ? `${delay}ms` : undefined,
      }}
    />
  );
}

/** A bar that fills from its left edge. */
export function Grow({
  on,
  x,
  y,
  width,
  height,
  className,
  delay = 0,
}: {
  on: boolean;
  x: number;
  y: number;
  width: number;
  height: number;
  className: string;
  delay?: number;
}) {
  return (
    <rect
      x={x}
      y={y}
      width={width}
      height={height}
      rx={height / 2}
      className={cn("ethr-story-grow", className)}
      style={{
        transform: `scaleX(${on ? 1 : 0})`,
        transitionDelay: on && delay ? `${delay}ms` : undefined,
      }}
    />
  );
}

export function Person({
  x,
  y,
  scale = 1,
  tone = "fill-primary/80",
  ghost = false,
}: {
  x: number;
  y: number;
  scale?: number;
  tone?: string;
  ghost?: boolean;
}) {
  const style = ghost
    ? {
        className: "fill-none stroke-foreground/35",
        strokeWidth: 2,
        strokeDasharray: "4 4",
      }
    : { className: tone };
  return (
    <g transform={`translate(${x} ${y}) scale(${scale})`}>
      <circle cx="0" cy="-26" r="12" {...style} />
      <path d="M-20 10 a20 20 0 0 1 40 0 v8 h-40z" {...style} />
    </g>
  );
}

export function Lines({
  x,
  y,
  widths,
  gap = 12,
  tone = "fill-foreground/25",
}: {
  x: number;
  y: number;
  widths: number[];
  gap?: number;
  tone?: string;
}) {
  return (
    <g>
      {widths.map((w, i) => (
        <rect
          key={i}
          x={x}
          y={y + i * gap}
          width={w}
          height="5"
          rx="2.5"
          className={tone}
        />
      ))}
    </g>
  );
}

export function Sheet({
  x,
  y,
  width,
  height,
  rx = 10,
}: {
  x: number;
  y: number;
  width: number;
  height: number;
  rx?: number;
}) {
  return (
    <rect
      x={x}
      y={y}
      width={width}
      height={height}
      rx={rx}
      className="fill-card stroke-border"
      strokeWidth="3"
    />
  );
}

/** A round badge with a tick: the "done" mark every story ends on. */
export function Tick({ x, y, r = 11 }: { x: number; y: number; r?: number }) {
  const k = r / 11;
  return (
    <g transform={`translate(${x} ${y})`}>
      <circle r={r} className="fill-status-success" />
      <path
        d={`M${-5 * k} ${0.5 * k} l${3.5 * k} ${3.5 * k} l${6.5 * k} ${-7.5 * k}`}
        className="stroke-white"
        strokeWidth={2.6 * k}
        fill="none"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </g>
  );
}

/** A round badge with a cross: the thing that went wrong. */
export function Cross({ x, y, r = 11 }: { x: number; y: number; r?: number }) {
  const k = r / 11;
  return (
    <g transform={`translate(${x} ${y})`}>
      <circle r={r} className="fill-status-error" />
      <path
        d={`M${-4 * k} ${-4 * k} l${8 * k} ${8 * k} M${4 * k} ${-4 * k} l${-8 * k} ${8 * k}`}
        className="stroke-white"
        strokeWidth={2.6 * k}
        strokeLinecap="round"
      />
    </g>
  );
}

export function Warning({ x, y }: { x: number; y: number }) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <path
        d="M0 -15 L15 11 H-15 Z"
        className="fill-status-warning stroke-status-warning"
        strokeWidth="4"
        strokeLinejoin="round"
      />
      <rect x="-1.75" y="-6" width="3.5" height="9" rx="1.75" fill="white" />
      <circle cx="0" cy="7" r="2" fill="white" />
    </g>
  );
}

/** A speech bubble holding a question mark: someone who cannot tell. */
export function Doubt({ x, y }: { x: number; y: number }) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <rect
        x="-20"
        y="-20"
        width="40"
        height="34"
        rx="10"
        className="fill-card stroke-status-warning"
        strokeWidth="2.5"
      />
      <path d="M-6 14 l-2 9 l10 -9z" className="fill-status-warning" />
      <text
        x="0"
        y="6"
        textAnchor="middle"
        fontSize="22"
        fontWeight="700"
        className="fill-status-warning"
      >
        ?
      </text>
    </g>
  );
}

export function Pin({
  x,
  y,
  tone = "fill-primary",
}: {
  x: number;
  y: number;
  tone?: string;
}) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <path
        d="M0 -9 a7 7 0 0 1 7 7 c0 5 -7 11 -7 11 s-7 -6 -7 -11 a7 7 0 0 1 7 -7z"
        className={tone}
      />
      <circle cy="-2" r="2.6" className="fill-card" />
    </g>
  );
}

export function Label({
  x,
  y,
  children,
  size = 14,
  tone = "fill-foreground",
  weight = 600,
  anchor = "middle",
}: {
  x: number;
  y: number;
  children: React.ReactNode;
  size?: number;
  tone?: string;
  weight?: number;
  anchor?: "start" | "middle" | "end";
}) {
  return (
    <text
      x={x}
      y={y}
      textAnchor={anchor}
      fontSize={size}
      fontWeight={weight}
      className={tone}
      style={{ fontVariantNumeric: "tabular-nums" }}
    >
      {children}
    </text>
  );
}

export function Phone({
  x,
  y,
  width = 104,
  height = 204,
  children,
}: {
  x: number;
  y: number;
  width?: number;
  height?: number;
  children?: React.ReactNode;
}) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <rect
        width={width}
        height={height}
        rx="18"
        className="fill-foreground/80"
      />
      <rect
        x="6"
        y="6"
        width={width - 12}
        height={height - 12}
        rx="13"
        className="fill-card"
      />
      <rect
        x={width / 2 - 14}
        y="12"
        width="28"
        height="4"
        rx="2"
        className="fill-foreground/30"
      />
      {children}
    </g>
  );
}
