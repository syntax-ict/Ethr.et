import type { Metadata } from "next";
import { SsoReturn } from "./sso-return";

export const metadata: Metadata = {
  title: "Signing in",
  description: "Completing single sign-on.",
  robots: { index: false, follow: false },
};

export default function Page() {
  return <SsoReturn />;
}
