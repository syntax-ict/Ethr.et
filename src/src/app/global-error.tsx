"use client";

import { useEffect } from "react";

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
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
            Application Error
          </h1>
          <p
            style={{
              marginTop: "0.5rem",
              color: "#64748B",
              maxWidth: "24rem",
            }}
          >
            A critical error occurred. Please refresh the page.
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
            Refresh Page
          </button>
        </div>
      </body>
    </html>
  );
}
