"use client";

import { Component, useEffect, useState, type ReactNode } from "react";
import * as Sentry from "@sentry/nextjs";
import { AlertTriangle, RefreshCw, WifiOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

/**
 * True for a code chunk that could not be fetched: the browser is offline
 * and the service worker never saw the chunk, or a deploy replaced it. The
 * names differ by bundler and browser, so the message is read too.
 */
export function isChunkLoadError(error: unknown): boolean {
  if (typeof error !== "object" || error === null) return false;
  const { name, message } = error as { name?: unknown; message?: unknown };
  if (name === "ChunkLoadError") return true;
  return (
    typeof message === "string" &&
    /Failed to load chunk|Loading chunk|Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i.test(
      message,
    )
  );
}

function useOnline(): boolean {
  const [online, setOnline] = useState(
    () => typeof navigator === "undefined" || navigator.onLine !== false,
  );
  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener("online", on);
    window.addEventListener("offline", off);
    return () => {
      window.removeEventListener("online", on);
      window.removeEventListener("offline", off);
    };
  }, []);
  return online;
}

function SectionFallback({
  missingCode,
  onTryAgain,
}: {
  missingCode: boolean;
  onTryAgain: () => void;
}) {
  const { t } = useT();
  const online = useOnline();

  // The section's code never reached this browser. Trying again in place
  // does not help: the bundler keeps the failed import failed until the page
  // loads again. So the answer is a reload, once there is a connection.
  if (missingCode) {
    return (
      <div
        role="status"
        data-testid="section-unavailable"
        className="flex items-start gap-3 rounded-lg border border-border bg-card p-4 text-sm"
      >
        <WifiOff
          className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
          aria-hidden="true"
        />
        <div className="min-w-0 flex-1 space-y-1">
          <p className="font-medium text-foreground">
            {online
              ? t("section.back_online", "You are back online.")
              : t("section.needs_connection", "This part needs a connection.")}
          </p>
          <p className="text-muted-foreground">
            {online
              ? t(
                  "section.reload_to_show",
                  "Reload the page to show this part.",
                )
              : t(
                  "section.needs_connection_hint",
                  "Reload the page when you are back online. The rest of the page works without it.",
                )}
          </p>
        </div>
        {online && (
          <Button
            size="sm"
            variant="outline"
            className="min-h-11 shrink-0"
            onClick={() => window.location.reload()}
          >
            <RefreshCw className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("section.reload", "Reload")}
          </Button>
        )}
      </div>
    );
  }

  return (
    <div
      role="status"
      data-testid="section-unavailable"
      className="flex items-start gap-3 rounded-lg border border-border bg-card p-4 text-sm"
    >
      <AlertTriangle
        className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
        aria-hidden="true"
      />
      <div className="min-w-0 flex-1 space-y-1">
        <p className="font-medium text-foreground">
          {t("section.failed", "This part could not be shown.")}
        </p>
        <p className="text-muted-foreground">
          {t("section.failed_hint", "The rest of the page works without it.")}
        </p>
      </div>
      <Button
        size="sm"
        variant="outline"
        className="min-h-11 shrink-0"
        onClick={onTryAgain}
      >
        <RefreshCw className="mr-2 h-4 w-4" aria-hidden="true" />
        {t("common.try_again", "Try again")}
      </Button>
    </div>
  );
}

interface Props {
  children: ReactNode;
}

interface State {
  error: unknown;
}

/**
 * Keeps one failing section of a page from taking the whole page down.
 *
 * Found on production, 2026-11-24: the dashboard's charts are imported only
 * when the reader scrolls near them, so a reader who had not scrolled online
 * had no copy in the service worker's cache. Opened offline, the import
 * failed, and the route's error page replaced the whole dashboard with
 * "Something went wrong". Caught here, it is one card that says why.
 */
export class SectionBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: unknown): State {
    return { error };
  }

  componentDidCatch(error: unknown) {
    // The route's error page reported what reached it; this boundary now
    // stops errors first, so it reports them itself. Code that could not be
    // fetched offline is expected and is not a fault.
    const offline =
      typeof navigator !== "undefined" && navigator.onLine === false;
    if (offline && isChunkLoadError(error)) return;
    // Guarded on the DSN so a build without one carries no Sentry code (see
    // instrumentation-client.ts).
    if (process.env.NEXT_PUBLIC_SENTRY_DSN) {
      Sentry.captureException(error, { tags: { boundary: "section" } });
    }
  }

  render() {
    if (this.state.error === null) return this.props.children;

    return (
      <SectionFallback
        missingCode={isChunkLoadError(this.state.error)}
        onTryAgain={() => this.setState({ error: null })}
      />
    );
  }
}
