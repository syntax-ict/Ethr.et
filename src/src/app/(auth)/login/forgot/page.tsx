import type { Metadata } from "next";
import { ForgotForm } from "./forgot-form";

export const metadata: Metadata = {
  title: "Reset your password",
  description: "Request a password reset link for your ETHR account.",
};

export default function Page() {
  return <ForgotForm />;
}
