import { MetadataRoute } from "next";

/**
 * Required for `output: "export"`, and harmless without it.
 *
 * A metadata route is dynamic by default, and `next build` under static export
 * stops with "Page /manifest.webmanifest couldn't be rendered statically because
 * it used `dynamic`". Measured 2026-09-18 (`7aed9d2`) as the FIRST of three
 * build-stopping blockers, and the only one that is a directive rather than an
 * architectural change - `docs/SHARED_HOSTING_AUDIT.md` §E.
 *
 * `app/og.png/route.tsx` already carries the same directive for the same reason,
 * so this is the established pattern here rather than a new one.
 *
 * It changes nothing under the shipped `output: "standalone"`: this function
 * returns a constant object with no request-time input, so forcing it static is
 * what it already effectively was.
 */
export const dynamic = "force-static";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "ETHR — Ethiopian Workforce OS",
    short_name: "ETHR",
    description:
      "Enterprise HR & attendance management for Ethiopian organizations",
    start_url: "/dashboard",
    display: "standalone",
    background_color: "#0f172a",
    theme_color: "#2563eb",
    orientation: "portrait-primary",
    icons: [
      { src: "/icons/icon-72.png", sizes: "72x72", type: "image/png" },
      { src: "/icons/icon-96.png", sizes: "96x96", type: "image/png" },
      { src: "/icons/icon-128.png", sizes: "128x128", type: "image/png" },
      { src: "/icons/icon-144.png", sizes: "144x144", type: "image/png" },
      { src: "/icons/icon-152.png", sizes: "152x152", type: "image/png" },
      {
        src: "/icons/icon-192.png",
        sizes: "192x192",
        type: "image/png",
        purpose: "maskable",
      },
      { src: "/icons/icon-384.png", sizes: "384x384", type: "image/png" },
      {
        src: "/icons/icon-512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "any",
      },
      {
        src: "/icons/icon-512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "maskable",
      },
    ],
    categories: ["business", "productivity"],
    lang: "en",
    dir: "ltr",
    scope: "/",
    shortcuts: [
      {
        name: "Check In",
        short_name: "Check In",
        description: "Record attendance check-in",
        url: "/attendance/my?action=check-in",
        icons: [{ src: "/icons/icon-96.png", sizes: "96x96" }],
      },
      {
        name: "My Payslips",
        short_name: "Payslips",
        description: "View my payslips",
        url: "/payroll/my",
        icons: [{ src: "/icons/icon-96.png", sizes: "96x96" }],
      },
    ],
  };
}
