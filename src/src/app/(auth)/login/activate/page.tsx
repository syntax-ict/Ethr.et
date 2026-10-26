import type { Metadata } from "next";
import { ActivateForm } from "./activate-form";

export const metadata: Metadata = {
  title: "Activate your account",
  description: "Activate your ETHR account with the code HR gave you.",
  // The code arrives in the address fragment. It is never sent to the server,
  // and the page must not leak the address onward either.
  referrer: "no-referrer",
  robots: { index: false, follow: false },
};

export default function Page() {
  return <ActivateForm />;
}
