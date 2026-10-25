"use client";

import { cn } from "@/lib/utils";
import { StoryFrame } from "./story-frame";
import {
  Draw,
  Grow,
  Label,
  Lines,
  Move,
  Part,
  Person,
  Pin,
  Tick,
} from "./story-parts";

/**
 * The hero's picture: one working day in an office, looping. Someone walks
 * in and checks in from a phone, someone else at the wall reader, a third
 * loses the network and is queued, then uploaded; payroll computes on the
 * wall screen under the Ethiopian rules and is approved; the payslips reach
 * each person's phone in their own script, with the Ethiopian date beside
 * the Gregorian one. It is the hero sentence, drawn.
 *
 * Sixteen seconds a loop, in eight steps of two: slower than the ledger's
 * stories, because it sits beside the heading and the call to action and
 * must read as calm. The settled frame, the last step, is the finished
 * day: every record ticked, payroll approved, both payslips delivered.
 */

const STEPS = 8;
const STEP_MS = 2000;

/** The wall screen's inner area. */
const SCREEN = { x: 198, y: 42, w: 244, h: 144 };

function MiniPhone({
  x,
  y,
  children,
}: {
  x: number;
  y: number;
  children?: React.ReactNode;
}) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <rect width="44" height="84" rx="8" className="fill-foreground/80" />
      <rect x="3" y="3" width="38" height="78" rx="6" className="fill-card" />
      <rect
        x="15"
        y="7"
        width="14"
        height="3"
        rx="1.5"
        className="fill-foreground/30"
      />
      {children}
    </g>
  );
}

/** A check-in row on the wall screen: an icon, a time and a tick. */
function RecordRow({
  i,
  time,
  kiosk = false,
  ticked = true,
}: {
  i: number;
  time: string;
  kiosk?: boolean;
  ticked?: boolean;
}) {
  const y = SCREEN.y + 30 + i * 34;
  return (
    <g>
      <rect
        x={SCREEN.x + 8}
        y={y}
        width="112"
        height="28"
        rx="7"
        className="fill-card stroke-border"
        strokeWidth="2"
      />
      {kiosk ? (
        <rect
          x={SCREEN.x + 17}
          y={y + 8}
          width="12"
          height="12"
          rx="2"
          className="fill-foreground/60"
        />
      ) : (
        <Pin x={SCREEN.x + 23} y={y + 15} />
      )}
      <Label x={SCREEN.x + 36} y={y + 19} size={14} anchor="start">
        {time}
      </Label>
      {ticked && <Tick x={SCREEN.x + 106} y={y + 14} r={8} />}
    </g>
  );
}

function HeroDay({ s }: { s: number }) {
  const online = s !== 3;
  const clock = s === 0 ? "08:00" : s === 1 ? "08:02" : "08:04";
  const right = SCREEN.x + 132;
  return (
    <>
      {/* The room: a wall, a door, two desks, three people. */}
      <rect
        x="40"
        y="26"
        width="560"
        height="204"
        rx="8"
        className="fill-card/70"
      />
      <rect
        x="30"
        y="92"
        width="54"
        height="142"
        rx="4"
        className="fill-primary/10 stroke-foreground/25"
        strokeWidth="3"
      />
      <circle cx="74" cy="166" r="3" className="fill-foreground/40" />
      {[60, 410].map((x) => (
        <g key={x}>
          <rect
            x={x}
            y="236"
            width="170"
            height="14"
            rx="4"
            className="fill-primary/30"
          />
          <rect
            x={x + 10}
            y="250"
            width="10"
            height="70"
            className="fill-foreground/30"
          />
          <rect
            x={x + 150}
            y="250"
            width="10"
            height="70"
            className="fill-foreground/30"
          />
        </g>
      ))}
      <Person x={470} y={258} scale={1.1} tone="fill-primary/70" />
      <Person x={530} y={260} scale={1} tone="fill-primary/85" />

      {/* The person who arrives through the door and sits down. */}
      <Move x={s === 0 ? -74 : 0} y={s === 0 ? -10 : 0}>
        <Person x={150} y={258} scale={1.1} tone="fill-primary" />
      </Move>

      {/* The wall screen. */}
      <rect
        x="190"
        y="34"
        width="260"
        height="160"
        rx="10"
        className="fill-foreground/80"
      />
      <rect
        x={SCREEN.x}
        y={SCREEN.y}
        width={SCREEN.w}
        height={SCREEN.h}
        rx="6"
        className="fill-card"
      />
      <rect
        x={SCREEN.x}
        y={SCREEN.y}
        width={SCREEN.w}
        height="22"
        rx="6"
        className="fill-primary/15"
      />
      <Part on={s < 6}>
        <Label
          x={SCREEN.x + 10}
          y={SCREEN.y + 16}
          size={14}
          anchor="start"
          tone="fill-primary"
        >
          {clock}
        </Label>
      </Part>
      <Part on={s >= 6} from="fade">
        <rect
          x={SCREEN.x + 8}
          y={SCREEN.y + 4}
          width="6"
          height="14"
          rx="2"
          className="fill-brand-accent"
        />
        <Label x={SCREEN.x + 20} y={SCREEN.y + 16} size={13} anchor="start">
          ጥቅምት 15
        </Label>
        <rect
          x={SCREEN.x + 92}
          y={SCREEN.y + 4}
          width="6"
          height="14"
          rx="2"
          className="fill-primary"
        />
        <Label x={SCREEN.x + 104} y={SCREEN.y + 16} size={13} anchor="start">
          25 Oct
        </Label>
      </Part>
      {/* The cloud the records sync to. */}
      <Part on={s >= 4} from="pop">
        <path
          d={`M${right + 56} ${SCREEN.y + 18} a7 7 0 0 1 2 -13 a9 9 0 0 1 17 2 a6 6 0 0 1 -1 11z`}
          className="fill-primary/30"
        />
        <Tick x={right + 84} y={SCREEN.y + 12} r={7} />
      </Part>

      {/* Three check-ins, one by one. */}
      {[0, 1, 2].map((i) => (
        <Part key={i} on={s < [1, 2, 4][i]}>
          <rect
            x={SCREEN.x + 8}
            y={SCREEN.y + 30 + i * 34}
            width="112"
            height="28"
            rx="7"
            className="fill-none stroke-foreground/20"
            strokeWidth="2"
            strokeDasharray="5 5"
          />
        </Part>
      ))}
      <Part on={s >= 1} from="left" delay={500}>
        <RecordRow i={0} time="08:02" />
      </Part>
      <Part on={s >= 2} from="left" delay={700}>
        <RecordRow i={1} time="08:04" kiosk />
      </Part>
      <Part on={s >= 4} from="right" delay={800}>
        <RecordRow i={2} time="08:06" />
      </Part>

      {/* Payroll on the right half: gross, the tax ladder, the pension split. */}
      <Part on={s >= 5} from="up">
        {[0, 1, 2].map((i) => (
          <circle
            key={i}
            cx={right + 10}
            cy={SCREEN.y + 44 + i * 32}
            r="4"
            className="fill-primary"
          />
        ))}
        <Grow
          on={s >= 5}
          x={right + 22}
          y={SCREEN.y + 39}
          width={80}
          height={10}
          className="fill-primary"
          delay={300}
        />
        {[0, 1, 2, 3, 4].map((k) => (
          <Part key={k} on={s >= 5} from="up" delay={600 + k * 120}>
            <rect
              x={right + 22 + k * 17}
              y={SCREEN.y + 82 - (5 + k * 3)}
              width="13"
              height={5 + k * 3}
              rx="2"
              className={k <= 2 ? "fill-brand-accent" : "fill-brand-accent/35"}
            />
          </Part>
        ))}
        <Grow
          on={s >= 5}
          x={right + 22}
          y={SCREEN.y + 103}
          width={30}
          height={10}
          className="fill-primary/70"
          delay={1100}
        />
        <Grow
          on={s >= 5}
          x={right + 56}
          y={SCREEN.y + 103}
          width={46}
          height={10}
          className="fill-chart-2"
          delay={1300}
        />
      </Part>
      <Part on={s >= 5} from="drop" delay={1600}>
        <g transform={`rotate(-12 ${right + 94} ${SCREEN.y + 128})`}>
          <circle
            cx={right + 94}
            cy={SCREEN.y + 128}
            r="16"
            className="fill-card stroke-status-success"
            strokeWidth="3"
          />
          <path
            d={`M${right + 87} ${SCREEN.y + 129} l5 5 l9 -11`}
            className="stroke-status-success"
            strokeWidth="3"
            fill="none"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </g>
      </Part>

      {/* The wall reader by the screen: a QR panel that scans at step 2. */}
      <rect
        x="468"
        y="84"
        width="54"
        height="66"
        rx="6"
        className="fill-foreground/80"
      />
      <rect
        x="474"
        y="90"
        width="42"
        height="42"
        rx="3"
        className="fill-card"
      />
      <g transform="translate(481 97)">
        <rect
          width="28"
          height="28"
          rx="2"
          className="fill-none stroke-foreground/70"
          strokeWidth="2.5"
        />
        <rect x="5" y="5" width="7" height="7" className="fill-foreground/70" />
        <rect
          x="16"
          y="5"
          width="7"
          height="7"
          className="fill-foreground/70"
        />
        <rect
          x="5"
          y="16"
          width="7"
          height="7"
          className="fill-foreground/70"
        />
        <rect
          x="17"
          y="17"
          width="3"
          height="3"
          className="fill-foreground/70"
        />
        <rect
          x="22"
          y="22"
          width="3"
          height="3"
          className="fill-foreground/70"
        />
      </g>
      <Part on={s === 2}>
        <g className={cn(s === 2 && "ethr-story-scan")}>
          <rect
            x="476"
            y="92"
            width="38"
            height="3"
            rx="1.5"
            className="fill-brand-accent"
          />
        </g>
      </Part>
      <Part on={s >= 2} from="pop" delay={600}>
        <Tick x={520} y={88} r={8} />
      </Part>

      {/* The first person's phone: check-in, then the payslip, in Latin. */}
      <Part on={s >= 1} from="up">
        <MiniPhone x={98} y={150}>
          <Part on={s < 6}>
            <circle cx="22" cy="46" r="13" className="fill-primary" />
            <circle
              cx="22"
              cy="46"
              r="13"
              className={cn(
                "fill-none stroke-primary",
                s === 1 && "ethr-story-ripple",
              )}
              strokeWidth="2"
              style={{ transformOrigin: "22px 46px" }}
              opacity="0"
            />
            <path
              d="M16 47 a6 6 0 0 1 12 0 M18 51 a4 4 0 0 1 8 0 v2 M22 46 v6"
              className="stroke-white"
              strokeWidth="1.6"
              fill="none"
              strokeLinecap="round"
            />
            <Part on={s >= 1} from="pop" delay={300}>
              <rect
                x="7"
                y="16"
                width="30"
                height="18"
                rx="4"
                className="fill-primary/10"
              />
              <Label x={22} y={29} size={10} tone="fill-primary">
                08:02
              </Label>
            </Part>
          </Part>
          <Part on={s >= 6} from="up" delay={400}>
            <Label x={22} y={38} size={20} tone="fill-primary" weight={700}>
              Aa
            </Label>
            <Lines
              x={9}
              y={48}
              widths={[26, 18, 22]}
              gap={7}
              tone="fill-primary/40"
            />
            <rect
              x="9"
              y="68"
              width="26"
              height="5"
              rx="2.5"
              className="fill-status-success"
            />
          </Part>
        </MiniPhone>
      </Part>

      {/* The third person's phone: offline, queued, then synced; then the
          payslip, in Ethiopic. */}
      <Part on={s >= 3} from="up">
        <MiniPhone x={588} y={150}>
          <Part on={s >= 3 && s < 6}>
            <rect
              x="7"
              y="40"
              width="30"
              height="16"
              rx="4"
              className="fill-card stroke-primary"
              strokeWidth="1.5"
            />
            <Pin x={14} y={48} />
            <Lines x={20} y={46} widths={[12]} />
          </Part>
          <Part on={s >= 6} from="up" delay={700}>
            <Label x={22} y={38} size={20} tone="fill-primary" weight={700}>
              አማ
            </Label>
            <Lines
              x={9}
              y={48}
              widths={[26, 18, 22]}
              gap={7}
              tone="fill-primary/40"
            />
            <rect
              x="9"
              y="68"
              width="26"
              height="5"
              rx="2.5"
              className="fill-status-success"
            />
          </Part>
        </MiniPhone>
      </Part>
      <Part on={s === 3} from="pop">
        <circle cx="586" cy="154" r="9" className="fill-brand-accent" />
        <Label
          x={586}
          y={158}
          size={11}
          tone="fill-brand-accent-foreground"
          weight={700}
        >
          1
        </Label>
      </Part>
      <Part on={s >= 3}>
        <g transform="translate(610 134)">
          <path
            d="M-14 -4 a20 20 0 0 1 28 0 M-7 3 a10 10 0 0 1 14 0"
            className={
              online ? "stroke-status-success" : "stroke-foreground/30"
            }
            strokeWidth="3"
            fill="none"
            strokeLinecap="round"
            style={{ transition: "stroke 0.4s" }}
          />
          <circle
            cy="9"
            r="2.5"
            className={online ? "fill-status-success" : "fill-foreground/30"}
          />
          <Part on={!online} from="pop">
            <line
              x1="-15"
              y1="-9"
              x2="15"
              y2="13"
              className="stroke-status-error"
              strokeWidth="3"
              strokeLinecap="round"
            />
          </Part>
        </g>
      </Part>
      {/* The queued check-in flying up into the screen when the line returns. */}
      <Part on={s === 4}>
        <Move
          x={s === 4 ? SCREEN.x + 8 - 595 : 0}
          y={s === 4 ? SCREEN.y + 98 - 190 : 0}
          delay={100}
        >
          <rect
            x="595"
            y="190"
            width="30"
            height="16"
            rx="4"
            className="fill-card stroke-primary"
            strokeWidth="1.5"
          />
          <Pin x={602} y={198} />
        </Move>
      </Part>
      <Draw
        on={s === 4}
        d={`M612 186 C 560 120, 420 ${SCREEN.y + 60}, ${SCREEN.x + 124} ${SCREEN.y + 112}`}
        className="stroke-primary/30"
        width={2}
      />

      {/* The settled day: everything on the wall is in, and says so. */}
      <Part on={s >= 7} from="pop">
        <Tick x={446} y={38} r={12} />
      </Part>
    </>
  );
}

/** The hero's looping picture, when the operator has uploaded none. */
export function HeroStory() {
  return (
    <StoryFrame
      scene="hero/hero"
      steps={STEPS}
      stepMs={STEP_MS}
      viewBox="0 0 640 360"
      className="h-full rounded-none shadow-none"
    >
      {(step) => (
        <>
          <rect
            width="640"
            height="360"
            className="fill-muted dark:fill-background"
          />
          <HeroDay s={step} />
        </>
      )}
    </StoryFrame>
  );
}
