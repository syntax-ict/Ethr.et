"use client";

import { Pause, Play } from "lucide-react";
import { useStoryClock } from "@/lib/hooks/useStoryClock";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * The problems ledger's animated stories: one short looping scene per row,
 * the "Today" pain for the first half and the "With ETHR" answer for the
 * second, drawn in SVG and moved by CSS transitions.
 *
 * Each story is a pure function of one step number from `useStoryClock`, so
 * a scene reads as a script ("at step 5 the stamp lands") and costs a
 * re-render every 1.5 s while on screen and nothing off it. Shapes are in the
 * page's own tokens, so light, dark and high contrast repaint for free. The
 * few words drawn inside the pictures are times, dates and the two scripts'
 * own letters, never a sentence, so neither language can garble; everything
 * a picture says is also said in the words beside it, which is why the SVG
 * is `aria-hidden`.
 *
 * The settled frame is the last step, the answer, and it is what the
 * prerendered HTML and a reduced-motion reader get. `globals.css` turns every
 * transition and loop off under reduced motion as well, for the case where
 * the preference changes while the page is open.
 */

const STEPS = 8;
const HALF = STEPS / 2;
const STEP_MS = 1500;

type From = "fade" | "up" | "down" | "left" | "right" | "pop" | "drop";

/** Something that is on screen at some steps and not at others. */
function Part({
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
function Move({
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
function Draw({
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
function Grow({
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

function Person({
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

function Lines({
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

function Sheet({
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
function Tick({ x, y, r = 11 }: { x: number; y: number; r?: number }) {
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
function Cross({ x, y, r = 11 }: { x: number; y: number; r?: number }) {
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

function Warning({ x, y }: { x: number; y: number }) {
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
function Doubt({ x, y }: { x: number; y: number }) {
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

function Pin({
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

function Label({
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

function Phone({
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

/* ------------------------------------------------------------------------ */
/* 1. Attendance on paper                                                    */
/* ------------------------------------------------------------------------ */

/**
 * Today: a shift register; a pen signs row three late while the clock runs,
 * overtime turns into an argument, and nobody can say who was there.
 * With ETHR: a kiosk and a phone check people in, each stamping a time and
 * place into the record, and the overtime is counted from it.
 */
function PaperAttendanceStory({ s }: { s: number }) {
  const today = s < HALF;
  const rowY = (i: number) => 104 + i * 27;
  return (
    <>
      <Part on={today} from="left">
        <Sheet x={56} y={62} width={196} height={214} />
        <rect
          x="124"
          y="50"
          width="60"
          height="22"
          rx="6"
          className="fill-foreground/60"
        />
        {[0, 1, 2, 3, 4, 5].map((i) => (
          <g key={i}>
            <rect
              x="74"
              y={rowY(i) - 9}
              width="14"
              height="14"
              rx="3"
              className="fill-card stroke-border"
              strokeWidth="2"
            />
            <rect
              x="98"
              y={rowY(i) + 4}
              width="94"
              height="2"
              rx="1"
              className="fill-foreground/15"
            />
            <rect
              x="204"
              y={rowY(i) - 4}
              width="30"
              height="7"
              rx="3.5"
              className="fill-foreground/20"
            />
          </g>
        ))}
        {[0, 1].map((i) => (
          <g key={i}>
            <path
              d={`M76 ${rowY(i) - 2} l4 5 l7 -9`}
              className="stroke-foreground/50"
              strokeWidth="2.5"
              fill="none"
              strokeLinecap="round"
            />
            <path
              d={`M102 ${rowY(i)} c6 -10 10 6 16 -2 s8 -8 14 0 s10 2 16 -3`}
              className="stroke-foreground/45"
              strokeWidth="2.5"
              fill="none"
              strokeLinecap="round"
            />
          </g>
        ))}
        <Draw
          on={today && s >= 1}
          d={`M102 ${rowY(2)} c6 -10 10 6 16 -2 s8 -8 14 0 s10 2 22 -4`}
          className="stroke-foreground/60"
          width={2.5}
        />
        <Part on={today && s >= 1} delay={700}>
          <rect
            x="204"
            y={rowY(2) - 4}
            width="30"
            height="7"
            rx="3.5"
            className="fill-status-warning"
          />
        </Part>
        <Part on={today && s >= 2} from="pop">
          <path
            d={`M204 ${rowY(4) - 6} l28 9 M204 ${rowY(4) + 3} l28 -9`}
            className="stroke-status-error"
            strokeWidth="2.5"
            strokeLinecap="round"
          />
        </Part>
      </Part>

      {/* The pen that signs late. */}
      <Part on={today && s === 1}>
        <Move x={s >= 1 ? 52 : 0}>
          <g transform={`translate(104 ${rowY(2) - 2}) rotate(35)`}>
            <rect
              x="-4"
              y="-40"
              width="8"
              height="34"
              rx="2"
              className="fill-primary"
            />
            <path d="M-4 -6 L0 2 L4 -6z" className="fill-foreground/70" />
          </g>
        </Move>
      </Part>

      {/* The clock keeps running; the argument starts; who was there? */}
      <Part on={today}>
        <circle
          cx="370"
          cy="112"
          r="44"
          className="fill-card stroke-border"
          strokeWidth="3"
        />
        {[0, 1, 2, 3].map((q) => (
          <rect
            key={q}
            x="368.5"
            y="74"
            width="3"
            height="7"
            rx="1.5"
            className="fill-foreground/30"
            transform={`rotate(${q * 90} 370 112)`}
          />
        ))}
        <line
          x1="370"
          y1="112"
          x2="351"
          y2="122"
          className="stroke-foreground/70"
          strokeWidth="4"
          strokeLinecap="round"
        />
        <g
          className={cn(today && s < 3 && "ethr-story-spin")}
          style={{ transformOrigin: "370px 112px" }}
        >
          <line
            x1="370"
            y1="112"
            x2="374"
            y2="80"
            className="stroke-primary"
            strokeWidth="3.5"
            strokeLinecap="round"
          />
        </g>
        <circle cx="370" cy="112" r="4" className="fill-primary" />
        <Person x={372} y={262} scale={1.15} />
      </Part>
      <Part on={today && s >= 2} from="pop">
        <Doubt x={420} y={196} />
      </Part>
      <Part on={today && s >= 3} from="up">
        <Person x={312} y={262} scale={1.0} ghost />
        <Person x={432} y={262} scale={1.0} ghost />
        <Doubt x={290} y={186} />
      </Part>

      {/* With ETHR: the kiosk. */}
      <Part on={!today} from="left">
        <rect
          x="40"
          y="70"
          width="136"
          height="100"
          rx="12"
          className="fill-foreground/80"
        />
        <rect
          x="48"
          y="78"
          width="120"
          height="84"
          rx="7"
          className="fill-card"
        />
        <g transform="translate(68 90)">
          <rect
            width="40"
            height="40"
            rx="4"
            className="fill-card stroke-foreground/70"
            strokeWidth="3"
          />
          <rect
            x="7"
            y="7"
            width="10"
            height="10"
            className="fill-foreground/70"
          />
          <rect
            x="23"
            y="7"
            width="10"
            height="10"
            className="fill-foreground/70"
          />
          <rect
            x="7"
            y="23"
            width="10"
            height="10"
            className="fill-foreground/70"
          />
          <rect
            x="24"
            y="24"
            width="4"
            height="4"
            className="fill-foreground/70"
          />
          <rect
            x="30"
            y="30"
            width="4"
            height="4"
            className="fill-foreground/70"
          />
        </g>
        <Part on={s === 6}>
          <g className={cn(s === 6 && "ethr-story-scan")}>
            <rect
              x="62"
              y="90"
              width="52"
              height="3"
              rx="1.5"
              className="fill-brand-accent"
            />
          </g>
        </Part>
        <Lines x={120} y={98} widths={[34, 24, 30]} gap={12} />
        <rect
          x="101"
          y="170"
          width="14"
          height="76"
          className="fill-foreground/45"
        />
        <rect
          x="72"
          y="244"
          width="72"
          height="10"
          rx="5"
          className="fill-foreground/45"
        />
      </Part>
      <Part on={s >= 6} from="pop" delay={700}>
        <Tick x={160} y={84} />
      </Part>

      {/* With ETHR: the phone. */}
      <Part on={!today} from="right" delay={150}>
        <Phone x={336} y={56}>
          <Part on={s >= 5} from="pop" delay={300}>
            <rect
              x="14"
              y="30"
              width="76"
              height="62"
              rx="9"
              className="fill-primary/10"
            />
            <Label x={52} y={58} size={17} tone="fill-primary">
              08:02
            </Label>
            <Pin x={30} y={76} />
            <rect
              x="42"
              y="73"
              width="38"
              height="5"
              rx="2.5"
              className="fill-primary/40"
            />
          </Part>
          <circle cx="52" cy="150" r="24" className="fill-primary" />
          <circle
            cx="52"
            cy="150"
            r="24"
            className={cn(
              "fill-none stroke-primary",
              s === 5 && "ethr-story-ripple",
            )}
            strokeWidth="3"
            style={{ transformOrigin: "52px 150px" }}
            opacity="0"
          />
          <path
            d="M42 152 a10 10 0 0 1 20 0 M46 158 a6 6 0 0 1 12 0 v4 M52 150 v10"
            className="stroke-white"
            strokeWidth="2.4"
            fill="none"
            strokeLinecap="round"
          />
        </Phone>
      </Part>

      {/* The record: what the overtime is counted from. */}
      {[
        { at: 5, y: 92, from: "right" as const, time: "08:02", kiosk: false },
        { at: 6, y: 138, from: "left" as const, time: "08:04", kiosk: true },
      ].map((row) => (
        <Part
          key={row.y}
          on={s >= row.at}
          from={row.from}
          delay={row.at === 5 ? 650 : 900}
        >
          <rect
            x="190"
            y={row.y}
            width="130"
            height="38"
            rx="9"
            className="fill-card stroke-border"
            strokeWidth="2"
          />
          {row.kiosk ? (
            <rect
              x="200"
              y={row.y + 12}
              width="14"
              height="14"
              rx="2"
              className="fill-foreground/60"
            />
          ) : (
            <Pin x={207} y={row.y + 20} />
          )}
          <Label x={222} y={row.y + 24} size={14} anchor="start">
            {row.time}
          </Label>
          <Tick x={302} y={row.y + 19} r={9} />
        </Part>
      ))}
      <Part on={s >= 7} from="up">
        <rect
          x="190"
          y="184"
          width="130"
          height="44"
          rx="9"
          className="fill-card stroke-border"
          strokeWidth="2"
        />
        <circle
          cx="207"
          cy="206"
          r="9"
          className="fill-none stroke-brand-accent"
          strokeWidth="2.5"
        />
        <path
          d="M207 201 v5 l3 3"
          className="stroke-brand-accent"
          strokeWidth="2"
          fill="none"
          strokeLinecap="round"
        />
        <rect
          x="224"
          y="202"
          width="84"
          height="8"
          rx="4"
          className="fill-foreground/10"
        />
        <Grow
          on={s >= 7}
          x={224}
          y={202}
          width={58}
          height={8}
          className="fill-brand-accent"
          delay={400}
        />
      </Part>
    </>
  );
}

/* ------------------------------------------------------------------------ */
/* 2. Payroll recomputed by hand                                             */
/* ------------------------------------------------------------------------ */

/**
 * Today: a spreadsheet; one cell is typed wrong and the error travels into a
 * payslip. With ETHR: gross pay, the income tax ladder, the pension split and
 * Pagume proration compute in turn, each leaves a trail into the payslip, and
 * an approval stamp lands before anyone sees it.
 */
function ManualPayrollStory({ s }: { s: number }) {
  const today = s < HALF;
  const cell = (r: number, c: number) => ({ x: 56 + c * 58, y: 78 + r * 32 });
  const bad = cell(3, 2);
  return (
    <>
      <Part on={today} from="left">
        <Sheet x={40} y={62} width={252} height={196} />
        {[0, 1, 2, 3, 4, 5].map((r) =>
          [0, 1, 2, 3].map((c) => {
            const { x, y } = cell(r, c);
            return (
              <g key={`${r}-${c}`}>
                <rect
                  x={x}
                  y={y}
                  width="50"
                  height="22"
                  rx="4"
                  className={
                    r === 0 ? "fill-primary/20" : "fill-foreground/[0.07]"
                  }
                />
                {r > 0 && (
                  <rect
                    x={x + 8}
                    y={y + 9}
                    width={18 + ((r * 3 + c * 5) % 4) * 6}
                    height="4"
                    rx="2"
                    className="fill-foreground/25"
                  />
                )}
              </g>
            );
          }),
        )}
        <Part on={today && s >= 1}>
          <rect
            x={bad.x - 2}
            y={bad.y - 2}
            width="54"
            height="26"
            rx="5"
            className="fill-none stroke-primary"
            strokeWidth="2.5"
          />
        </Part>
        <Part on={today && s === 1}>
          <rect
            x={bad.x + 38}
            y={bad.y + 4}
            width="2.5"
            height="14"
            className={cn("fill-primary", s === 1 && "ethr-story-blink")}
          />
        </Part>
        <Part on={today && s >= 2} from="pop">
          <rect
            x={bad.x}
            y={bad.y}
            width="50"
            height="22"
            rx="4"
            className="fill-status-error"
          />
          <rect
            x={bad.x + 8}
            y={bad.y + 9}
            width="34"
            height="4"
            rx="2"
            fill="white"
          />
        </Part>
      </Part>

      {/* The cursor that types the wrong value. */}
      <Part on={today && s >= 1 && s <= 2}>
        <Move x={s >= 1 ? 0 : 70} y={s >= 1 ? 0 : 60}>
          <g transform={`translate(${bad.x + 30} ${bad.y + 14})`}>
            <path
              d="M0 0 l0 22 l6 -6 l5 11 l4 -2 l-5 -10 l8 0z"
              className="fill-foreground stroke-card"
              strokeWidth="1.5"
              strokeLinejoin="round"
            />
          </g>
        </Move>
      </Part>

      <Draw
        on={today && s >= 2}
        d={`M${bad.x + 52} ${bad.y + 11} C 290 ${bad.y + 11}, 300 214, 348 214`}
        className="stroke-status-error"
        width={3}
        delay={350}
      />

      {/* Today's payslip, wrong where the cell was wrong. */}
      <Part on={today && s >= 2} from="right" delay={200}>
        <Sheet x={340} y={68} width={110} height={180} rx={8} />
        <rect
          x="354"
          y="84"
          width="48"
          height="9"
          rx="4.5"
          className="fill-primary/60"
        />
        <Lines x={354} y={110} widths={[80, 62, 72, 56, 76]} gap={16} />
        <rect
          x="354"
          y="208"
          width="82"
          height="12"
          rx="4"
          className="fill-foreground/25"
        />
      </Part>
      <Part on={today && s >= 3} from="fade">
        <rect
          x="354"
          y="208"
          width="82"
          height="12"
          rx="4"
          className="fill-status-error"
        />
      </Part>
      <Part on={today && s >= 3} from="pop" delay={250}>
        <Warning x={446} y={76} />
      </Part>

      {/* With ETHR: the calculation, one rule at a time. */}
      <Part on={!today} from="left">
        <Sheet x={40} y={62} width={236} height={196} />
      </Part>
      {[0, 1, 2, 3].map((i) => {
        const y = 82 + i * 44;
        return (
          <Part
            key={i}
            on={s >= (i < 2 ? 4 : 5)}
            from="left"
            delay={(i % 2) * 450 + 200}
          >
            <circle cx="64" cy={y + 12} r="11" className="fill-primary/15" />
            <circle cx="64" cy={y + 12} r="4" className="fill-primary" />
            {i === 0 && (
              <Grow
                on={s >= 4}
                x={86}
                y={y + 7}
                width={140}
                height={10}
                className="fill-primary"
                delay={350}
              />
            )}
            {i === 1 &&
              [0, 1, 2, 3, 4, 5].map((k) => (
                <rect
                  key={k}
                  x={86 + k * 23}
                  y={y + 20 - (5 + k * 3)}
                  width="18"
                  height={5 + k * 3}
                  rx="2"
                  className={
                    k <= 3 ? "fill-brand-accent" : "fill-brand-accent/35"
                  }
                />
              ))}
            {i === 2 && (
              <>
                <Grow
                  on={s >= 5}
                  x={86}
                  y={y + 7}
                  width={52}
                  height={10}
                  className="fill-primary/70"
                  delay={500}
                />
                <Grow
                  on={s >= 5}
                  x={142}
                  y={y + 7}
                  width={80}
                  height={10}
                  className="fill-chart-2"
                  delay={750}
                />
              </>
            )}
            {i === 3 &&
              [0, 1, 2, 3, 4].map((k) => (
                <rect
                  key={k}
                  x={86 + k * 20}
                  y={y + 4}
                  width="15"
                  height="15"
                  rx="3"
                  className={
                    k < 3
                      ? "fill-brand-accent"
                      : "fill-none stroke-brand-accent"
                  }
                  strokeWidth="2"
                />
              ))}
            <Part
              on={s >= (i < 2 ? 4 : 5)}
              from="pop"
              delay={(i % 2) * 450 + 900}
            >
              <Tick x={254} y={y + 12} r={9} />
            </Part>
          </Part>
        );
      })}

      {[0, 1, 2, 3].map((i) => (
        <Draw
          key={i}
          on={s >= 6}
          d={`M266 ${94 + i * 44} C 300 ${94 + i * 44}, 310 ${112 + i * 22}, 352 ${112 + i * 22}`}
          className="stroke-primary/50"
          width={2}
          delay={i * 160}
        />
      ))}

      {/* The payslip waits for the calculation rather than for a person. */}
      <Part on={s === 4 || s === 5}>
        <rect
          x="340"
          y="68"
          width="110"
          height="180"
          rx="8"
          className="fill-none stroke-foreground/25"
          strokeWidth="2.5"
          strokeDasharray="6 6"
        />
      </Part>
      <Part on={s >= 6} from="right">
        <Sheet x={340} y={68} width={110} height={180} rx={8} />
        <rect
          x="354"
          y="84"
          width="48"
          height="9"
          rx="4.5"
          className="fill-primary/60"
        />
        {[0, 1, 2, 3].map((i) => (
          <rect
            key={i}
            x="354"
            y={110 + i * 22}
            width={[78, 60, 70, 52][i]}
            height="5"
            rx="2.5"
            className={
              i === 1 || i === 3 ? "fill-brand-accent" : "fill-primary/50"
            }
          />
        ))}
        <rect
          x="354"
          y="208"
          width="82"
          height="12"
          rx="4"
          className="fill-status-success"
        />
      </Part>
      <Part on={s >= 7} from="drop">
        <g transform="rotate(-12 424 218)">
          <circle
            cx="424"
            cy="218"
            r="25"
            className="fill-card stroke-status-success"
            strokeWidth="3.5"
          />
          <circle
            cx="424"
            cy="218"
            r="19"
            className="fill-none stroke-status-success/40"
            strokeWidth="1.5"
          />
          <path
            d="M414 219 l7 7 l13 -15"
            className="stroke-status-success"
            strokeWidth="4"
            fill="none"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </g>
      </Part>
    </>
  );
}

/* ------------------------------------------------------------------------ */
/* 3. Leave balances in spreadsheets                                         */
/* ------------------------------------------------------------------------ */

/**
 * Today: the balances sit in a locked file; a request arrives and waits on an
 * hourglass; the one person who can open it is away; the days are tallied by
 * hand on a calendar, and one is miscounted. With ETHR: the employee's own
 * balance on a phone, a request that flies to the manager's inbox and is
 * approved, and the days counted on the Ethiopian calendar, Pagume and a
 * holiday included.
 */
function LeaveSpreadsheetsStory({ s }: { s: number }) {
  const today = s < HALF;
  const balance = s >= 7 ? 15 : 18;
  return (
    <>
      <Part on={today} from="left">
        <Sheet x={40} y={62} width={172} height={140} />
        <rect
          x="42"
          y="64"
          width="168"
          height="26"
          rx="8"
          className="fill-primary/15"
        />
        {[0, 1, 2, 3].map((r) =>
          [0, 1, 2, 3, 4, 5].map((c) => (
            <rect
              key={`${r}-${c}`}
              x={54 + c * 25}
              y={100 + r * 24}
              width="19"
              height="15"
              rx="3"
              className={
                (r * 6 + c) % 7 === 3
                  ? "fill-primary/45"
                  : "fill-foreground/[0.07]"
              }
            />
          )),
        )}
        <g>
          <path
            d="M178 84 v-9 a10 10 0 0 1 20 0 v9"
            className="stroke-foreground/70"
            strokeWidth="4.5"
            fill="none"
          />
          <rect
            x="172"
            y="82"
            width="32"
            height="26"
            rx="5"
            className="fill-foreground/75"
          />
          <circle cx="188" cy="94" r="4" className="fill-card" />
        </g>
      </Part>
      <Part on={today && s < 2}>
        <Person x={126} y={268} scale={1.1} />
      </Part>
      <Part on={today && s >= 2}>
        <Person x={126} y={268} scale={1.1} ghost />
      </Part>
      <Part on={today}>
        <Person x={404} y={268} scale={1.1} tone="fill-primary/60" />
      </Part>

      {/* The request that waits. */}
      <Part on={today && s >= 1}>
        <Move x={s >= 1 ? 0 : 150} y={s >= 1 ? 0 : 50}>
          <rect
            x="230"
            y="160"
            width="66"
            height="46"
            rx="7"
            className="fill-card stroke-primary"
            strokeWidth="2.5"
          />
          <rect
            x="240"
            y="170"
            width="14"
            height="14"
            rx="3"
            className="fill-primary/60"
          />
          <Lines x={260} y={172} widths={[26, 18]} gap={9} />
          <Lines x={240} y={192} widths={[46]} />
        </Move>
      </Part>
      <Part on={today && s >= 1} from="pop" delay={700}>
        <g
          className={cn(today && s >= 1 && "ethr-story-flip")}
          style={{ transformOrigin: "263px 134px" }}
        >
          <path
            d="M251 118 h24 M251 150 h24 M254 118 c0 12 18 12 18 16 s-18 4 -18 16 M272 118 c0 12 -18 12 -18 16 s18 4 18 16"
            className="stroke-status-warning"
            strokeWidth="3"
            fill="none"
            strokeLinecap="round"
          />
          <path d="M258 146 h10 l-5 -6z" className="fill-status-warning" />
        </g>
      </Part>

      {/* Counted by hand, and one day miscounted. */}
      <Part on={today && s >= 3} from="up">
        <Sheet x={316} y={58} width={132} height={110} />
        <rect
          x="318"
          y="60"
          width="128"
          height="18"
          rx="8"
          className="fill-foreground/35"
        />
        {[0, 1, 2, 3].map((r) =>
          [0, 1, 2, 3, 4, 5].map((c) => (
            <rect
              key={`${r}-${c}`}
              x={326 + c * 19.5}
              y={86 + r * 19}
              width="15"
              height="14"
              rx="2.5"
              className="fill-foreground/[0.07]"
            />
          )),
        )}
      </Part>
      {[0, 1, 2, 3].map((k) => (
        <Draw
          key={k}
          on={today && s >= 3}
          d={`M${328 + k * 19.5} ${110} l11 -8`}
          className="stroke-foreground/60"
          width={2.5}
          delay={300 + k * 180}
        />
      ))}
      <Part on={today && s >= 3} from="pop" delay={1100}>
        <Cross x={414} y={126} r={9} />
      </Part>

      {/* With ETHR: the balance on the employee's own phone. */}
      <Part on={!today} from="left">
        <Phone x={44} y={52}>
          <circle
            cx="52"
            cy="76"
            r="28"
            className="fill-none stroke-foreground/10"
            strokeWidth="9"
          />
          <circle
            cx="52"
            cy="76"
            r="28"
            pathLength={100}
            transform="rotate(-90 52 76)"
            className="ethr-story-ring fill-none stroke-primary"
            strokeWidth="9"
            strokeLinecap="round"
            style={{ strokeDasharray: `${balance * 4} 100` }}
          />
          <Label x={52} y={82} size={17} tone="fill-foreground">
            {balance}
          </Label>
          <Lines x={20} y={120} widths={[64, 48]} gap={12} />
          <rect
            x="20"
            y="152"
            width="64"
            height="20"
            rx="10"
            className={cn("fill-primary", s === 4 && "ethr-story-press")}
            style={{ transformOrigin: "52px 162px" }}
          />
          <rect
            x="36"
            y="160"
            width="32"
            height="4"
            rx="2"
            className="fill-white/80"
          />
        </Phone>
      </Part>

      <Part on={!today} from="right" delay={150}>
        <Sheet x={318} y={60} width={132} height={150} />
        <path
          d="M334 84 h14 l4 6 h12 l4 -6 h14"
          className="stroke-primary"
          strokeWidth="2.5"
          fill="none"
          strokeLinejoin="round"
        />
        <Lines x={334} y={152} widths={[96, 70]} gap={12} />
        <Lines x={334} y={182} widths={[86, 60]} gap={12} />
      </Part>
      <Part on={!today}>
        <Person x={384} y={270} scale={1.1} tone="fill-primary/60" />
      </Part>

      {/* The request, from the phone to the manager's inbox. */}
      <Part on={s >= 5}>
        <Move x={s >= 5 ? 0 : -226} y={s >= 5 ? 0 : 66}>
          <rect
            x="330"
            y="96"
            width="108"
            height="44"
            rx="7"
            className={cn(
              "fill-card",
              s >= 6 ? "stroke-status-success" : "stroke-primary",
            )}
            strokeWidth="2.5"
          />
          <rect
            x="340"
            y="106"
            width="14"
            height="14"
            rx="3"
            className="fill-primary/60"
          />
          <Lines x={360} y={108} widths={[46, 30]} gap={9} />
          <Lines x={340} y={128} widths={[60]} />
        </Move>
      </Part>
      <Part on={s >= 6} from="pop" delay={250}>
        <Tick x={436} y={98} />
      </Part>

      {/* Counted on the Ethiopian calendar, Pagume and a holiday included. */}
      <Part on={s >= 6} from="up" delay={600}>
        <Sheet x={164} y={150} width={140} height={110} />
        <rect
          x="166"
          y="152"
          width="136"
          height="18"
          rx="8"
          className="fill-brand-accent"
        />
        {[0, 1, 2, 3].map((r) =>
          [0, 1, 2, 3, 4, 5, 6].map((c) => {
            const pagume = r === 3;
            if (pagume && c > 4) return null;
            return (
              <rect
                key={`${r}-${c}`}
                x={174 + c * 18}
                y={178 + r * 19}
                width="14"
                height="14"
                rx="2.5"
                className={
                  pagume
                    ? "fill-none stroke-brand-accent"
                    : "fill-foreground/[0.07]"
                }
                strokeWidth="1.5"
              />
            );
          }),
        )}
        <circle
          cx={174 + 5 * 18 + 7}
          cy={178 + 19 + 7}
          r="4"
          className="fill-brand-accent"
        />
      </Part>
      {[1, 2, 3].map((c, k) => (
        <Part key={c} on={s >= 7} from="pop" delay={200 + k * 220}>
          <rect
            x={174 + c * 18}
            y={178 + 19}
            width="14"
            height="14"
            rx="2.5"
            className="fill-primary"
          />
        </Part>
      ))}
    </>
  );
}

/* ------------------------------------------------------------------------ */
/* 4. Branches without a connection                                          */
/* ------------------------------------------------------------------------ */

/**
 * Today: a branch is connected, the line drops, the day's check-ins come out
 * of the device and are lost. With ETHR: the same drop, but the check-ins
 * queue on the device, and when the connection returns they travel up to
 * the server by themselves.
 */
function BranchesOfflineStory({ s }: { s: number }) {
  const today = s < HALF;
  const connected = s === 0 || s >= 6;
  const queued = s >= 7 ? 0 : s >= 5 ? 3 : 1;
  const card = (i: number) => ({ x: 272, y: 246 - i * 26 });
  return (
    <>
      {/* The branch, the device and the server stay; only the line changes. */}
      <path d="M32 144 L106 94 L180 144z" className="fill-primary/60" />
      <rect
        x="42"
        y="142"
        width="128"
        height="120"
        rx="4"
        className="fill-card stroke-border"
        strokeWidth="3"
      />
      {[0, 1, 2].map((r) =>
        [0, 1, 2].map((c) =>
          r === 2 && c === 1 ? null : (
            <rect
              key={`${r}-${c}`}
              x={58 + c * 36}
              y={156 + r * 34}
              width="24"
              height="20"
              rx="3"
              className="fill-primary/20"
            />
          ),
        ),
      )}
      <rect
        x="92"
        y="222"
        width="28"
        height="40"
        rx="3"
        className="fill-primary/40"
      />

      <g transform="translate(332 104)">
        <path
          d="M0 74 a32 32 0 0 1 16 -60 a42 42 0 0 1 80 8 a28 28 0 0 1 -5 52z"
          className="fill-card stroke-border"
          strokeWidth="3"
        />
        <Lines
          x={26}
          y={40}
          widths={[40, 54]}
          gap={12}
          tone="fill-primary/30"
        />
      </g>
      <Part on={s >= 7} from="pop" delay={1100}>
        <Tick x={420} y={110} r={12} />
      </Part>

      <Part on={connected}>
        <path
          d="M180 170 H330"
          className="stroke-primary/30"
          strokeWidth="4"
          strokeLinecap="round"
        />
        <path
          d="M180 170 H330"
          className={cn("stroke-primary", connected && "ethr-story-flow")}
          strokeWidth="4"
          strokeLinecap="round"
          strokeDasharray="4 14"
        />
      </Part>
      <Part on={!connected}>
        <path
          d="M180 170 H232 M276 170 H330"
          className="stroke-foreground/25"
          strokeWidth="4"
          strokeLinecap="round"
          strokeDasharray="2 10"
        />
      </Part>

      <g transform="translate(254 136)">
        <path
          d="M-24 -6 a34 34 0 0 1 48 0 M-13 6 a18 18 0 0 1 26 0"
          className={
            connected ? "stroke-status-success" : "stroke-foreground/30"
          }
          strokeWidth="5"
          fill="none"
          strokeLinecap="round"
          style={{ transition: "stroke 0.4s" }}
        />
        <circle
          cy="17"
          r="4"
          className={connected ? "fill-status-success" : "fill-foreground/30"}
        />
        <Part on={!connected} from="pop">
          <line
            x1="-26"
            y1="-16"
            x2="26"
            y2="24"
            className="stroke-status-error"
            strokeWidth="5"
            strokeLinecap="round"
          />
        </Part>
      </g>

      <Phone x={196} y={190} width={62} height={98}>
        <Lines x={14} y={30} widths={[34, 26, 30]} gap={10} />
      </Phone>
      <Part on={!today && queued > 0} from="pop">
        <circle cx="254" cy="194" r="12" className="fill-brand-accent" />
        <Label
          x={254}
          y={199}
          size={13}
          tone="fill-brand-accent-foreground"
          weight={700}
        >
          {queued}
        </Label>
      </Part>

      {/* Today: the day's check-ins come out of the device and are lost. */}
      {[0, 1, 2].map((i) => {
        const { x, y } = card(i);
        return (
          <g key={`lost-${i}`}>
            <Part on={today && s === 2} from="up" delay={i * 200}>
              <rect
                x={x}
                y={y}
                width="62"
                height="20"
                rx="5"
                className="fill-card stroke-primary"
                strokeWidth="2"
              />
              <Pin x={x + 11} y={y + 11} />
              <Lines x={x + 22} y={y + 8} widths={[30]} />
            </Part>
            <Part on={today && s === 3} delay={i * 120}>
              <rect
                x={x}
                y={y}
                width="62"
                height="20"
                rx="5"
                className="fill-none stroke-status-error/60"
                strokeWidth="2"
                strokeDasharray="4 4"
              />
            </Part>
          </g>
        );
      })}
      <Part on={today && s === 3} from="pop" delay={400}>
        <Cross x={350} y={232} r={12} />
      </Part>

      {/* With ETHR: they queue, then go up by themselves. */}
      {[0, 1, 2].map((i) => {
        const { x, y } = card(i);
        const shown = !today && (i === 0 || s >= 5);
        const sent = s >= 7;
        return (
          <Part
            key={`queued-${i}`}
            on={shown}
            from="up"
            delay={s === 5 ? i * 220 : 0}
          >
            <Move
              x={sent ? 382 - (x + 31) * 0.5 : 0}
              y={sent ? 138 - (y + 10) * 0.5 : 0}
              scale={sent ? 0.5 : 1}
              delay={sent ? i * 180 : 0}
            >
              <rect
                x={x}
                y={y}
                width="62"
                height="20"
                rx="5"
                className="fill-card stroke-primary"
                strokeWidth="2"
              />
              <Pin x={x + 11} y={y + 11} />
              <Lines x={x + 22} y={y + 8} widths={[30]} />
            </Move>
          </Part>
        );
      })}
    </>
  );
}

/* ------------------------------------------------------------------------ */
/* 5. One system, two languages                                              */
/* ------------------------------------------------------------------------ */

/**
 * Today: one screen in one script; the finance officer is fine, the floor
 * supervisor cannot read it, and the payslip arrives in the same script.
 * With ETHR: a language switch, then each person at their own screen in
 * their own script, notifications in each, and the Ethiopian date beside the
 * Gregorian one.
 */
function BilingualStaffStory({ s }: { s: number }) {
  const today = s < HALF;
  return (
    <>
      <Person x={84} y={268} scale={1.2} />
      <Person x={396} y={268} scale={1.2} tone="fill-primary/60" />

      <Part on={today || s === 4} from="fade">
        <Sheet x={150} y={60} width={180} height={118} />
        <rect
          x="226"
          y="178"
          width="28"
          height="18"
          className="fill-foreground/40"
        />
        <rect
          x="198"
          y="196"
          width="84"
          height="8"
          rx="4"
          className="fill-foreground/40"
        />
        <Label x={196} y={112} size={32} tone="fill-primary" weight={700}>
          Aa
        </Label>
        <Lines x={232} y={90} widths={[78, 60, 70]} gap={12} />
        <Lines x={170} y={134} widths={[140]} />
      </Part>

      {/* Today: one of them can read it, the other cannot. */}
      <Part on={today && s >= 1} from="pop">
        <rect
          x="44"
          y="152"
          width="64"
          height="40"
          rx="10"
          className="fill-card stroke-border"
          strokeWidth="2"
        />
        <path d="M70 192 l-2 9 l10 -9z" className="fill-border" />
        <Tick x={76} y={172} r={11} />
      </Part>
      <Part on={today && s >= 1} from="pop" delay={400}>
        <Doubt x={414} y={172} />
      </Part>
      <Part on={today && s >= 3} from="left">
        <g transform="rotate(8 360 200)">
          <rect
            x="336"
            y="170"
            width="44"
            height="58"
            rx="5"
            className="fill-card stroke-border"
            strokeWidth="2"
          />
          <Label x={352} y={194} size={15} tone="fill-primary" weight={700}>
            A
          </Label>
          <Lines x={344} y={204} widths={[28, 22]} gap={8} />
        </g>
      </Part>
      <Part on={today && s >= 3} from="pop" delay={350}>
        <Warning x={330} y={232} />
      </Part>

      {/* With ETHR: the switch. */}
      <Part on={s === 4} from="pop" delay={250}>
        <rect
          x="196"
          y="146"
          width="88"
          height="24"
          rx="12"
          className="fill-foreground/10"
        />
        <g className={cn(s === 4 && "ethr-story-toggle")}>
          <rect
            x="196"
            y="146"
            width="44"
            height="24"
            rx="12"
            className="fill-primary"
          />
        </g>
        <Label x={218} y={162} size={12} weight={700}>
          EN
        </Label>
        <Label x={262} y={163} size={12} weight={700}>
          አማ
        </Label>
      </Part>

      {/* Each at their own screen, in their own script. */}
      {[
        { x: 28, glyph: "Aa", from: "right" as const },
        { x: 312, glyph: "አማ", from: "left" as const },
      ].map((screen) => (
        <Part key={screen.x} on={s >= 5} from={screen.from}>
          <Sheet x={screen.x} y={58} width={140} height={96} />
          <rect
            x={screen.x + 58}
            y="154"
            width="24"
            height="12"
            className="fill-foreground/40"
          />
          <rect
            x={screen.x + 40}
            y="166"
            width="60"
            height="7"
            rx="3.5"
            className="fill-foreground/40"
          />
          <Label
            x={screen.x + 40}
            y={104}
            size={26}
            tone="fill-primary"
            weight={700}
          >
            {screen.glyph}
          </Label>
          <Lines x={screen.x + 76} y={84} widths={[48, 36, 44]} gap={12} />
          <Lines x={screen.x + 16} y={126} widths={[108]} />
        </Part>
      ))}
      {[
        { x: 120, glyph: "A" },
        { x: 300, glyph: "ሀ" },
      ].map((note, i) => (
        <Part key={note.x} on={s >= 6} from="pop" delay={i * 300}>
          <rect
            x={note.x}
            y="186"
            width="60"
            height="30"
            rx="8"
            className="fill-card stroke-brand-accent"
            strokeWidth="2"
          />
          <Label
            x={note.x + 16}
            y={206}
            size={14}
            tone="fill-primary"
            weight={700}
          >
            {note.glyph}
          </Label>
          <Tick x={note.x + 44} y={201} r={8} />
        </Part>
      ))}

      {/* The Ethiopian date beside the Gregorian one. */}
      <Part on={s >= 7} from="up">
        <rect
          x="182"
          y="70"
          width="54"
          height="66"
          rx="8"
          className="fill-card stroke-border"
          strokeWidth="2"
        />
        <rect
          x="182"
          y="70"
          width="54"
          height="18"
          rx="8"
          className="fill-brand-accent"
        />
        <Label x={209} y={114} size={20} weight={700}>
          15
        </Label>
        <Label
          x={209}
          y={129}
          size={10}
          tone="fill-muted-foreground"
          weight={500}
        >
          ጥቅምት
        </Label>
      </Part>
      <Part on={s >= 7} from="up" delay={250}>
        <rect
          x="244"
          y="70"
          width="54"
          height="66"
          rx="8"
          className="fill-card stroke-border"
          strokeWidth="2"
        />
        <rect
          x="244"
          y="70"
          width="54"
          height="18"
          rx="8"
          className="fill-primary"
        />
        <Label x={271} y={114} size={20} weight={700}>
          25
        </Label>
        <Label
          x={271}
          y={129}
          size={10}
          tone="fill-muted-foreground"
          weight={500}
        >
          Oct
        </Label>
      </Part>
    </>
  );
}

/* ------------------------------------------------------------------------ */
/* 6. Contracts that expire unnoticed                                        */
/* ------------------------------------------------------------------------ */

/**
 * Today: the days tick past a contract's end date, the date turns red, no
 * bell rings, and the renewal is signed late. With ETHR: the employee's
 * digital file opens, the bell rings before the end date, the contract is
 * renewed and its new end date set, in time.
 */
function ContractsExpiringStory({ s }: { s: number }) {
  const today = s < HALF;
  const cell = (r: number, c: number) => ({ x: 54 + c * 27, y: 104 + r * 34 });
  const markerCol = today ? [1, 3, 5, 6][s] : [0, 1, 2, 2][s - HALF];
  const end = cell(2, 4);
  const renewed = cell(3, 6);
  const marker = cell(2, markerCol);
  return (
    <>
      <Sheet x={40} y={62} width={214} height={196} />
      <rect
        x="42"
        y="64"
        width="210"
        height="30"
        rx="8"
        className="fill-primary"
      />
      <rect
        x="58"
        y="76"
        width="60"
        height="6"
        rx="3"
        className="fill-white/70"
      />
      {[0, 1, 2, 3].map((r) =>
        [0, 1, 2, 3, 4, 5, 6].map((c) => {
          const { x, y } = cell(r, c);
          return (
            <rect
              key={`${r}-${c}`}
              x={x}
              y={y}
              width="22"
              height="24"
              rx="4"
              className="fill-foreground/[0.07]"
            />
          );
        }),
      )}

      <Part on={s < 6}>
        <rect
          x={end.x}
          y={end.y}
          width="22"
          height="24"
          rx="4"
          className="fill-none stroke-brand-accent"
          strokeWidth="3"
        />
      </Part>
      <Part on={today && s >= 2} from="pop">
        <rect
          x={end.x}
          y={end.y}
          width="22"
          height="24"
          rx="4"
          className="fill-status-error"
        />
      </Part>
      <Part on={s >= 6} from="pop" delay={500}>
        <rect
          x={renewed.x}
          y={renewed.y}
          width="22"
          height="24"
          rx="4"
          className="fill-brand-accent/30 stroke-brand-accent"
          strokeWidth="3"
        />
      </Part>

      {/* Today, moving through the month. */}
      <g
        className="ethr-story-move"
        style={{ transform: `translate(${marker.x - cell(2, 0).x}px, 0px)` }}
      >
        <rect
          x={cell(2, 0).x - 4}
          y={marker.y - 4}
          width="30"
          height="32"
          rx="7"
          className="fill-none stroke-foreground/70"
          strokeWidth="2.5"
        />
      </g>

      {/* Today: the contract, the silent bell, the late signature. */}
      <Part on={today} from="right">
        <Sheet x={300} y={70} width={124} height={164} rx={8} />
        <rect
          x="316"
          y="86"
          width="56"
          height="9"
          rx="4.5"
          className="fill-primary/60"
        />
        <Lines x={316} y={110} widths={[90, 72, 84, 60]} gap={14} />
        <rect
          x="316"
          y="172"
          width="40"
          height="8"
          rx="4"
          className="fill-brand-accent"
        />
        <rect
          x="316"
          y="210"
          width="76"
          height="2"
          rx="1"
          className="fill-foreground/25"
        />
      </Part>
      <Part on={today && s >= 2} from="pop" delay={300}>
        <g transform="translate(446 76)">
          <path
            d="M-12 8 c0 -4 3 -5 3 -12 a9 9 0 0 1 18 0 c0 7 3 8 3 12z"
            className="fill-foreground/25"
          />
          <circle cy="12" r="3.5" className="fill-foreground/25" />
          <line
            x1="-15"
            y1="-14"
            x2="15"
            y2="16"
            className="stroke-status-error"
            strokeWidth="3.5"
            strokeLinecap="round"
          />
        </g>
      </Part>
      <Draw
        on={today && s >= 3}
        d="M318 204 c6 -12 10 4 16 -4 s8 -10 14 -1 s10 2 20 -5"
        className="stroke-foreground/60"
        width={2.5}
      />
      <Part on={today && s >= 3} from="pop" delay={700}>
        <Cross x={418} y={226} r={12} />
      </Part>

      {/* With ETHR: the employee's file. */}
      <Part on={!today} from="right">
        <path
          d="M296 110 h46 l10 12 h96 v124 h-152z"
          className="fill-primary/30"
        />
      </Part>
      {[
        { rotate: -10, at: 0 },
        { rotate: 0, at: 1 },
        { rotate: 9, at: 2 },
      ].map((doc) => (
        <Part key={doc.at} on={!today} from="up" delay={300 + doc.at * 200}>
          <g transform={`rotate(${doc.rotate} ${336 + doc.at * 36} 200)`}>
            <rect
              x={312 + doc.at * 36}
              y="104"
              width="54"
              height="76"
              rx="5"
              className="fill-card stroke-border"
              strokeWidth="2"
            />
            {doc.at === 0 && (
              <>
                <circle cx="326" cy="122" r="7" className="fill-primary/40" />
                <Lines x={338} y={118} widths={[20, 14]} gap={8} />
                <Lines x={320} y={142} widths={[38, 30]} gap={9} />
              </>
            )}
            {doc.at === 1 && (
              <>
                <rect
                  x="356"
                  y="114"
                  width="30"
                  height="6"
                  rx="3"
                  className="fill-primary/60"
                />
                <Lines x={356} y={128} widths={[38, 30, 36]} gap={9} />
              </>
            )}
            {doc.at === 2 && (
              <>
                <Lines x={392} y={116} widths={[36, 28, 34]} gap={9} />
                <circle cx="409" cy="158" r="8" className="fill-brand-accent" />
              </>
            )}
          </g>
        </Part>
      ))}
      <Part on={!today} from="right">
        <path d="M290 170 h164 l-8 80 h-148z" className="fill-primary/70" />
      </Part>
      <Draw
        on={s >= 6}
        d="M352 214 c6 -12 10 4 16 -4 s8 -10 14 -1 s10 2 20 -5"
        className="stroke-white"
        width={3}
        delay={200}
      />
      <Part on={s >= 7} from="pop">
        <Tick x={440} y={178} r={13} />
      </Part>

      {/* The reminder, before the date. */}
      <Part on={s >= 5} from="pop">
        <g
          className={cn(s === 5 && "ethr-story-shake")}
          style={{ transformOrigin: "446px 62px" }}
        >
          <path
            d="M434 84 c0 -4 3 -5 3 -12 a9 9 0 0 1 18 0 c0 7 3 8 3 12z"
            className="fill-brand-accent"
          />
          <circle cx="446" cy="88" r="3.5" className="fill-brand-accent" />
        </g>
      </Part>
      <Part on={s >= 5} from="right" delay={350}>
        <rect
          x="304"
          y="52"
          width="118"
          height="38"
          rx="9"
          className="fill-card stroke-brand-accent"
          strokeWidth="2.5"
        />
        <rect
          x="314"
          y="62"
          width="18"
          height="18"
          rx="3"
          className="fill-none stroke-brand-accent"
          strokeWidth="2"
        />
        <rect
          x="314"
          y="62"
          width="18"
          height="6"
          rx="2"
          className="fill-brand-accent"
        />
        <Lines x={340} y={64} widths={[70, 48]} gap={10} />
      </Part>
    </>
  );
}

/**
 * A row the operator added later, which no story was drawn for: loose papers
 * that shuffle, then the same papers filed into one place and ticked.
 */
function GenericStory({ s }: { s: number }) {
  const today = s < HALF;
  return (
    <>
      {[
        { x: 96, y: 92, r: -12 },
        { x: 190, y: 120, r: 8 },
        { x: 290, y: 84, r: -5 },
      ].map((p, i) => (
        <Part key={i} on={today} from="up" delay={i * 150}>
          <g transform={`rotate(${p.r} ${p.x + 45} ${p.y + 60})`}>
            <g
              className={cn(today && "ethr-story-jitter")}
              style={{ animationDelay: `${i * -400}ms` }}
            >
              <Sheet x={p.x} y={p.y} width={90} height={120} rx={6} />
              <Lines
                x={p.x + 14}
                y={p.y + 22}
                widths={[60, 44, 54, 36]}
                gap={14}
              />
            </g>
          </g>
        </Part>
      ))}
      <Part on={today && s >= 2} from="pop">
        <Doubt x={250} y={250} />
      </Part>
      <Part on={!today} from="up">
        <Sheet x={110} y={70} width={260} height={180} />
      </Part>
      {[0, 1, 2].map((i) => (
        <Part key={i} on={s >= HALF + i} from="left" delay={200}>
          <rect
            x="130"
            y={92 + i * 48}
            width="220"
            height="36"
            rx="8"
            className="fill-foreground/[0.06]"
          />
          <Lines x={146} y={102 + i * 48} widths={[120, 80]} gap={10} />
          <Tick x={330} y={110 + i * 48} r={9} />
        </Part>
      ))}
    </>
  );
}

const STORIES: Record<string, (props: { s: number }) => React.ReactElement> = {
  paper_attendance: PaperAttendanceStory,
  manual_payroll: ManualPayrollStory,
  leave_spreadsheets: LeaveSpreadsheetsStory,
  branches_offline: BranchesOfflineStory,
  bilingual_staff: BilingualStaffStory,
  contracts_expiring: ContractsExpiringStory,
};

/** The slugs a story exists for, so a test can check every seeded row has one. */
export const STORY_SLUGS = Object.keys(STORIES);

/**
 * One row's story, framed like a short video: the scene, a "Today" / "With
 * ETHR" marker that follows it, a progress line, and a pause control.
 */
export function ProblemStory({ slug }: { slug: string }) {
  const { t } = useT();
  const { ref, step, cycle, moving, playing, paused, toggle } =
    useStoryClock<HTMLDivElement>(STEPS, STEP_MS);
  const Story = STORIES[slug] ?? GenericStory;
  const today = step < HALF;
  const todayLabel = t("marketing.landing.today_label", "Today");
  const ethrLabel = t("marketing.landing.with_ethr_label", "With ETHR");

  return (
    <div
      ref={ref}
      className="ethr-story relative overflow-hidden rounded-2xl bg-card shadow-sm ring-1 ring-white/10"
      data-scene={`problems/${slug}`}
      data-step={step}
      data-playing={playing ? "" : undefined}
    >
      <svg
        viewBox="0 0 480 300"
        className="block h-auto w-full"
        aria-hidden="true"
        focusable="false"
      >
        <rect
          width="480"
          height="300"
          className="fill-muted dark:fill-background"
        />
        <rect
          width="480"
          height="300"
          className="ethr-story-tint fill-status-warning/[0.06]"
          style={{ opacity: today ? 1 : 0 }}
        />
        <rect
          width="480"
          height="300"
          className="ethr-story-tint fill-primary/[0.06]"
          style={{ opacity: today ? 0 : 1 }}
        />
        <Story s={step} />
      </svg>

      <div
        className="pointer-events-none absolute start-3 top-3 flex gap-1.5 text-xs font-medium"
        aria-hidden="true"
      >
        <span
          className={cn(
            "ethr-story-chip rounded-full px-2.5 py-1",
            today
              ? "bg-foreground text-background"
              : "bg-card/80 text-muted-foreground",
          )}
        >
          {todayLabel}
        </span>
        <span
          className={cn(
            "ethr-story-chip rounded-full px-2.5 py-1",
            today
              ? "bg-card/80 text-muted-foreground"
              : "bg-primary text-primary-foreground",
          )}
        >
          {ethrLabel}
        </span>
      </div>

      {moving && (
        <>
          <span
            className="absolute inset-x-0 bottom-0 h-1 bg-foreground/10"
            aria-hidden="true"
          >
            <span
              key={cycle}
              className="ethr-story-progress block h-full bg-brand-accent"
              style={{
                animationDuration: `${STEPS * STEP_MS}ms`,
                animationPlayState: playing ? "running" : "paused",
              }}
            />
          </span>
          <button
            type="button"
            onClick={toggle}
            aria-label={
              paused
                ? t("marketing.landing.story_play", "Play the animation")
                : t("marketing.landing.story_pause", "Pause the animation")
            }
            className="absolute bottom-2.5 end-2.5 flex h-8 w-8 items-center justify-center rounded-full bg-card/90 text-foreground shadow-sm ring-1 ring-border transition-colors hover:bg-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {paused ? (
              <Play className="h-3.5 w-3.5" aria-hidden="true" />
            ) : (
              <Pause className="h-3.5 w-3.5" aria-hidden="true" />
            )}
          </button>
        </>
      )}
    </div>
  );
}
