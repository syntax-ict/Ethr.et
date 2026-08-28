import type { Metadata } from "next";
import { ResetPasswordForm } from "./reset-form";

export const metadata: Metadata = {
  title: "Choose a new password",
  description: "Set a new password for your ETHR account.",
  robots: { index: false, follow: false },
};

export default function Page() {
  return <ResetPasswordForm />;
}
