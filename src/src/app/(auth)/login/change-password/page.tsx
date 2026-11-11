import type { Metadata } from "next";
import { ChangePasswordForm } from "./change-password-form";

export const metadata: Metadata = {
  title: "Choose your own password",
  description: "Replace the starting password you were given with your own.",
  robots: { index: false, follow: false },
};

export default function Page() {
  return <ChangePasswordForm />;
}
