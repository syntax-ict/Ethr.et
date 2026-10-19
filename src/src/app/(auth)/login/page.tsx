import type { Metadata } from "next";
import { LoginForm } from "./login-form";

/**
 * `noindex, follow` (2026-10-19): the shared sign-in page says nothing a
 * search should land on. robots.txt no longer disallows /login, because on an
 * organisation's own host this same export is its public portal; there
 * PortalHead::inject() strips this tag and writes the organisation's own.
 */
export const metadata: Metadata = {
  title: "Sign in",
  description: "Sign in to your ETHR workspace.",
  robots: { index: false, follow: true },
};

export default function Page() {
  return <LoginForm />;
}
