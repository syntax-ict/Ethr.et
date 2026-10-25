/**
 * The drawn scenes: one per capability row, shown when a row has no
 * uploaded picture. The problems ledger's rows and the hero are animated
 * stories instead, in `problem-stories.tsx` and `hero-story.tsx`.
 *
 * Flat, in the page's own tokens (`fill-primary`, `fill-muted`, and so on),
 * so they repaint for light, dark and high-contrast for free, carry no
 * licence, and can never garble a script because they carry no text. Each is
 * `aria-hidden`: the words beside it say everything the picture says.

 */

const VIEW = "0 0 480 300";

function Frame({ children }: { children: React.ReactNode }) {
  return (
    <svg
      viewBox={VIEW}
      className="h-full w-full"
      aria-hidden="true"
      focusable="false"
    >
      <rect
        width="100%"
        height="100%"
        rx="16"
        className="fill-muted/60 dark:fill-muted/30"
      />
      {children}
    </svg>
  );
}

/** A person: a head and shoulders, in two tones. */
function Person({
  x,
  y,
  scale = 1,
  tone = "fill-primary",
}: {
  x: number;
  y: number;
  scale?: number;
  tone?: string;
}) {
  return (
    <g transform={`translate(${x} ${y}) scale(${scale})`}>
      <circle cx="0" cy="-26" r="12" className={tone} />
      <path d="M-20 10 a20 20 0 0 1 40 0 v8 h-40z" className={tone} />
    </g>
  );
}

/** Rows of text-like bars, never letters. */
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

function Phone({
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
      <rect width="104" height="200" rx="18" className="fill-foreground/80" />
      <rect x="6" y="6" width="92" height="188" rx="14" className="fill-card" />
      <rect
        x="38"
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

export function RecordsScene() {
  return (
    <Frame>
      <g transform="translate(44 60)">
        <rect
          width="210"
          height="180"
          rx="12"
          className="fill-card stroke-border"
          strokeWidth="3"
        />
        <circle cx="46" cy="50" r="26" className="fill-primary/25" />
        <circle cx="46" cy="42" r="10" className="fill-primary" />
        <path d="M28 70 a18 18 0 0 1 36 0z" className="fill-primary" />
        <Lines
          x={88}
          y={34}
          widths={[100, 70]}
          gap={16}
          tone="fill-foreground/60"
        />
        <Lines x={20} y={100} widths={[170, 140, 160]} gap={18} />
        <rect
          x="20"
          y="152"
          width="60"
          height="14"
          rx="7"
          className="fill-status-success/40"
        />
        <rect
          x="88"
          y="152"
          width="60"
          height="14"
          rx="7"
          className="fill-primary/25"
        />
      </g>
      <g transform="translate(290 70)">
        <rect
          x="56"
          y="0"
          width="48"
          height="26"
          rx="6"
          className="fill-primary"
        />
        <path
          d="M80 26 v18 M30 44 h100 M30 44 v16 M80 44 v16 M130 44 v16"
          className="stroke-foreground/40"
          strokeWidth="3"
          fill="none"
        />
        {[6, 56, 106].map((x) => (
          <rect
            key={x}
            x={x}
            y="60"
            width="48"
            height="26"
            rx="6"
            className="fill-primary/40"
          />
        ))}
        <path
          d="M30 86 v16 M130 86 v16"
          className="stroke-foreground/40"
          strokeWidth="3"
        />
        {[6, 106].map((x) => (
          <rect
            key={x}
            x={x}
            y="102"
            width="48"
            height="26"
            rx="6"
            className="fill-primary/20"
          />
        ))}
      </g>
      <Person x={400} y={270} scale={1} tone="fill-primary/80" />
    </Frame>
  );
}

export function TimeScene() {
  return (
    <Frame>
      <Phone x={70} y={50}>
        <circle cx="52" cy="80" r="30" className="fill-primary" />
        <path
          d="M40 80 l8 9 l18 -20"
          className="stroke-card"
          strokeWidth="5"
          fill="none"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <Lines x={22} y={130} widths={[60, 40]} gap={14} />
        <rect
          x="22"
          y="160"
          width="60"
          height="12"
          rx="6"
          className="fill-status-success/40"
        />
      </Phone>
      <g transform="translate(240 70)">
        <rect
          width="110"
          height="110"
          rx="10"
          className="fill-card stroke-border"
          strokeWidth="3"
        />
        {[0, 1, 2, 3, 4, 5].map((r) =>
          [0, 1, 2, 3, 4, 5].map((c) =>
            (r * 7 + c * 3) % 4 === 0 ||
            (r < 2 && c < 2) ||
            (r < 2 && c > 3) ||
            (r > 3 && c < 2) ? (
              <rect
                key={`${r}-${c}`}
                x={13 + c * 14}
                y={13 + r * 14}
                width="12"
                height="12"
                className="fill-foreground/70"
              />
            ) : null,
          ),
        )}
      </g>
      <g transform="translate(380 60)">
        <rect width="60" height="140" rx="8" className="fill-foreground/70" />
        <rect
          x="8"
          y="10"
          width="44"
          height="70"
          rx="4"
          className="fill-card"
        />
        <rect
          x="16"
          y="96"
          width="28"
          height="28"
          rx="6"
          className="fill-primary"
        />
      </g>
      <rect
        x="230"
        y="210"
        width="210"
        height="10"
        rx="5"
        className="fill-foreground/20"
      />
      <Person x={270} y={290} scale={0.9} tone="fill-primary/80" />
      <Person x={330} y={290} scale={0.9} tone="fill-primary/60" />
    </Frame>
  );
}

export function PayrollScene() {
  return (
    <Frame>
      <Phone x={60} y={50}>
        <rect
          x="18"
          y="30"
          width="68"
          height="8"
          rx="4"
          className="fill-foreground/60"
        />
        <rect
          x="18"
          y="52"
          width="68"
          height="6"
          rx="3"
          className="fill-primary/40"
        />
        <rect
          x="18"
          y="66"
          width="28"
          height="6"
          rx="3"
          className="fill-status-warning/60"
        />
        <rect
          x="18"
          y="80"
          width="48"
          height="6"
          rx="3"
          className="fill-status-success/60"
        />
        <Lines x={18} y={104} widths={[60, 44, 56]} gap={14} />
        <rect
          x="18"
          y="158"
          width="68"
          height="14"
          rx="7"
          className="fill-primary"
        />
      </Phone>
      <g transform="translate(220 70)">
        <rect
          width="210"
          height="160"
          rx="12"
          className="fill-card stroke-border"
          strokeWidth="3"
        />
        <rect
          x="20"
          y="24"
          width="170"
          height="14"
          rx="7"
          className="fill-primary/35"
        />
        <rect
          x="20"
          y="52"
          width="60"
          height="14"
          rx="7"
          className="fill-status-warning/50"
        />
        <rect
          x="20"
          y="80"
          width="112"
          height="14"
          rx="7"
          className="fill-status-success/50"
        />
        <circle cx="176" cy="126" r="16" className="fill-status-success/25" />
        <path
          d="M168 126 l6 6 l12 -14"
          className="stroke-status-success"
          strokeWidth="4"
          fill="none"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <Lines x={20} y={118} widths={[90, 60]} gap={14} />
      </g>
    </Frame>
  );
}

export function SelfServiceScene() {
  return (
    <Frame>
      <g transform="translate(60 40)">
        <rect width="230" height="170" rx="14" className="fill-foreground/80" />
        <rect
          x="8"
          y="8"
          width="214"
          height="154"
          rx="10"
          className="fill-card"
        />
        {[0, 1, 2].map((i) => (
          <g key={i} transform={`translate(24 ${26 + i * 42})`}>
            <circle cx="12" cy="12" r="12" className="fill-primary/25" />
            <Lines x={34} y={6} widths={[100, 60]} gap={12} />
            <rect
              x="150"
              y="2"
              width="32"
              height="20"
              rx="10"
              className={i === 1 ? "fill-muted" : "fill-status-success/40"}
            />
            {i !== 1 && (
              <path
                d="M160 12 l5 5 l10 -10"
                className="stroke-status-success"
                strokeWidth="3"
                fill="none"
                strokeLinecap="round"
              />
            )}
          </g>
        ))}
      </g>
      <Phone x={340} y={60}>
        <Lines x={18} y={34} widths={[60, 40, 54]} gap={16} />
        <rect
          x="18"
          y="100"
          width="68"
          height="18"
          rx="9"
          className="fill-primary"
        />
        <rect
          x="18"
          y="128"
          width="68"
          height="18"
          rx="9"
          className="fill-muted"
        />
      </Phone>
      <Person x={160} y={282} scale={1} tone="fill-primary/80" />
    </Frame>
  );
}

export function AnalyticsScene() {
  return (
    <Frame>
      <g transform="translate(50 46)">
        <rect
          width="380"
          height="200"
          rx="12"
          className="fill-card stroke-border"
          strokeWidth="3"
        />
        {[70, 120, 90, 150, 110, 170, 130].map((h, i) => (
          <rect
            key={i}
            x={28 + i * 34}
            y={176 - h}
            width="22"
            height={h}
            rx="4"
            className={i === 5 ? "fill-primary" : "fill-primary/35"}
          />
        ))}
        <polyline
          points="28,120 62,100 96,112 130,70 164,86 198,52 232,60 266,40"
          className="stroke-status-success"
          strokeWidth="4"
          fill="none"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <rect
          x="290"
          y="24"
          width="70"
          height="40"
          rx="8"
          className="fill-muted"
        />
        <rect
          x="290"
          y="76"
          width="70"
          height="40"
          rx="8"
          className="fill-muted"
        />
        <rect
          x="290"
          y="128"
          width="70"
          height="40"
          rx="8"
          className="fill-status-warning/30"
        />
      </g>
      <rect
        x="220"
        y="246"
        width="40"
        height="22"
        className="fill-foreground/40"
      />
      <rect
        x="180"
        y="268"
        width="120"
        height="8"
        rx="4"
        className="fill-foreground/40"
      />
    </Frame>
  );
}

const SCENES: Record<string, () => React.ReactElement> = {
  "capabilities/records": RecordsScene,
  "capabilities/time": TimeScene,
  "capabilities/payroll": PayrollScene,
  "capabilities/self_service": SelfServiceScene,
  "capabilities/analytics": AnalyticsScene,
};

/** The slugs a scene exists for, so a test can check every seeded row has one. */
export const SCENE_KEYS = Object.keys(SCENES);

/**
 * The scene for a row, or a plain one for a row added later in the console
 * that no scene was drawn for.
 */
export function Scene({ kind, slug }: { kind: string; slug: string }) {
  const Component = SCENES[`${kind}/${slug}`] ?? GenericScene;
  return <Component />;
}

function GenericScene() {
  return (
    <Frame>
      <rect
        x="80"
        y="70"
        width="320"
        height="160"
        rx="12"
        className="fill-card stroke-border"
        strokeWidth="3"
      />
      <Lines x={110} y={100} widths={[200, 150, 240, 120]} gap={26} />
      <Person x={400} y={270} scale={1} tone="fill-primary/80" />
    </Frame>
  );
}
