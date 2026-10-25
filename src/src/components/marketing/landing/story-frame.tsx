"use client";

import { Pause, Play } from "lucide-react";
import { useStoryClock } from "@/lib/hooks/useStoryClock";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * The frame every animated story shares, like a short video's player: the
 * scene drawn for the current step, anything laid over it, a progress line
 * for the loop, and a pause control (WCAG 2.2.2, for anything that moves
 * beside other content for more than five seconds).
 *
 * The clock is `useStoryClock`: settled on the last step in the prerendered
 * HTML, started only after hydration when motion is allowed, and only while
 * on screen. The controls and the progress line appear only once it has
 * started, so a crawler, a reader with scripts off and a reduced-motion
 * reader get the finished frame and nothing to press.
 */
export function StoryFrame({
  scene,
  steps,
  stepMs,
  viewBox,
  className,
  overlay,
  children,
}: {
  /** The `data-scene` value, `kind/slug`, which the tests look up. */
  scene: string;
  steps: number;
  stepMs: number;
  viewBox: string;
  className?: string;
  /** HTML laid over the picture, for the step; chips, never meaning. */
  overlay?: (step: number) => React.ReactNode;
  /** The picture, for the step. */
  children: (step: number) => React.ReactNode;
}) {
  const { t } = useT();
  const { ref, step, cycle, moving, playing, paused, toggle } =
    useStoryClock<HTMLDivElement>(steps, stepMs);

  return (
    <div
      ref={ref}
      className={cn(
        "ethr-story relative overflow-hidden rounded-2xl bg-card shadow-sm",
        className,
      )}
      data-scene={scene}
      data-step={step}
      data-playing={playing ? "" : undefined}
    >
      <svg
        viewBox={viewBox}
        className="block h-auto w-full"
        aria-hidden="true"
        focusable="false"
      >
        {children(step)}
      </svg>

      {overlay?.(step)}

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
                animationDuration: `${steps * stepMs}ms`,
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
