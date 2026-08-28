import type { Metadata } from "next";
import { OtpForm } from "./otp-form";

export const metadata: Metadata = {
  title: "Sign in with code",
  description: "Sign in to ETHR using a one-time code sent to your phone.",
};

export default function Page() {
  return <OtpForm />;
}
