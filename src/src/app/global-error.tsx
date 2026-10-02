"use client";

import { useEffect } from "react";

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  // Static, bilingual text (audit N38). This replaces the root layout, so the
  // page locale is gone, and it is the screen nothing else catches: loading the
  // i18n module here would add a localStorage read that can itself throw. The
  // Amharic is copied from existing translations, pinned to them by
  // global-error.test.tsx: error.title and common.try_again.
  useEffect(() => {
    console.error("Global error:", error);
  }, [error]);

  return (
    <html lang="en">
      <body
        style={{
          margin: 0,
          display: "flex",
          minHeight: "100vh",
          alignItems: "center",
          justifyContent: "center",
          fontFamily:
            'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
          backgroundColor: "#F8FAFC",
          color: "#0F172A",
        }}
      >
        <div style={{ textAlign: "center", padding: "2rem" }}>
          <div
            style={{
              width: "4rem",
              height: "4rem",
              margin: "0 auto",
              borderRadius: "1rem",
              backgroundColor: "#FEE2E2",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              fontSize: "1.5rem",
            }}
          >
            !
          </div>
          <h1
            style={{
              marginTop: "1.5rem",
              fontSize: "1.5rem",
              fontWeight: 700,
            }}
          >
            Something went wrong
          </h1>
          <p
            lang="am"
            style={{
              marginTop: "0.25rem",
              fontSize: "1.125rem",
              fontWeight: 600,
              color: "#334155",
            }}
          >
            የሆነ ችግር ተፈጥሯል
          </p>
          {error.digest && (
            <p
              style={{
                marginTop: "0.5rem",
                fontFamily: "monospace",
                fontSize: "0.75rem",
                color: "#94A3B8",
              }}
            >
              Error ID: {error.digest}
            </p>
          )}
          <button
            onClick={reset}
            style={{
              marginTop: "2rem",
              padding: "0.625rem 1.5rem",
              backgroundColor: "#0F4C75",
              color: "white",
              border: "none",
              borderRadius: "0.375rem",
              fontSize: "0.875rem",
              fontWeight: 500,
              cursor: "pointer",
            }}
          >
            Try again · <span lang="am">እንደገና ሞክር</span>
          </button>
        </div>
      </body>
    </html>
  );
}
