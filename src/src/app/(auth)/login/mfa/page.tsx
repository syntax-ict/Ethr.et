import type { Metadata } from "next";
import { MfaForm } from "./mfa-form";

export const metadata: Metadata = {
  title: "Two-factor authentication",
  description: "Enter your authenticator code to finish signing in.",
  robots: { index: false, follow: false },
};

export default function Page() {
  return <MfaForm />;
}
