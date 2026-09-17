import { ImageResponse } from "next/og";

/**
 * The card every shared ethr.et link renders as.
 *
 * There was no OG image, so a link pasted into Slack, Telegram or LinkedIn
 * showed the bare URL — on a product whose first distribution channel is
 * someone sending the link to a colleague.
 *
 * Deliberately text-only and drawn with layout primitives rather than an asset:
 *
 *  - No logo file. `platform_settings.logo_url` is operator-supplied and may be
 *    absent or an arbitrary host, and this image is generated at build time
 *    where neither the database nor that host is reachable. Fetching it would
 *    make the build depend on a third party, and failing to fetch it would fail
 *    the build.
 *  - No Amharic. ImageResponse embeds only the fonts it is handed, and Ethiopic
 *    is not in the default set, so Amharic text would render as tofu boxes.
 *    Shipping a broken script on the card that represents an Ethiopian product
 *    is worse than shipping English; the page it links to is bilingual.
 *  - No metrics, no customer count. The same rule as the landing page: this is
 *    generated at build time and cannot read the database, and inventing a
 *    figure here would put back exactly what this branch removed.
 */
export const alt = "ETHR — Ethiopian Workforce Operating System";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

export default async function Image() {
  return new ImageResponse(
    <div
      style={{
        width: "100%",
        height: "100%",
        display: "flex",
        flexDirection: "column",
        justifyContent: "center",
        padding: "80px",
        background: "#0b1220",
        color: "#f8fafc",
        fontFamily: "sans-serif",
      }}
    >
      {/* The tibeb band the site's footer carries, as flat rectangles —
            ImageResponse supports no CSS gradients with repeating stops. */}
      <div style={{ display: "flex", height: 10, marginBottom: 56 }}>
        {Array.from({ length: 24 }).map((_, i) => (
          <div
            key={i}
            style={{
              width: 40,
              height: 10,
              background: i % 3 === 2 ? "#f59e0b" : "#0ea5e9",
            }}
          />
        ))}
      </div>

      <div style={{ display: "flex", alignItems: "center", gap: 24 }}>
        <div
          style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            width: 88,
            height: 88,
            borderRadius: 20,
            background: "#0ea5e9",
            fontSize: 52,
            fontWeight: 700,
            color: "#0b1220",
          }}
        >
          E
        </div>
        <div style={{ display: "flex", fontSize: 76, fontWeight: 700 }}>
          ETHR
        </div>
      </div>

      <div
        style={{
          display: "flex",
          marginTop: 32,
          fontSize: 42,
          lineHeight: 1.3,
          color: "#cbd5e1",
          maxWidth: 900,
        }}
      >
        The complete HR platform for Ethiopian organizations
      </div>

      <div
        style={{
          display: "flex",
          marginTop: 40,
          fontSize: 28,
          color: "#94a3b8",
        }}
      >
        Attendance · Payroll · Leave — works offline
      </div>
    </div>,
    size,
  );
}
