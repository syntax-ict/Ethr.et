import type { Metadata } from "next";
import { ClaimSession } from "./claim-session";

export const metadata: Metadata = {
  title: "Starting session",
  description: "Claiming a support session.",
  robots: { index: false, follow: false },
};

export default function Page() {
  return <ClaimSession />;
}
