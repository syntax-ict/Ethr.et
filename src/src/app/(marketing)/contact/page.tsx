import type { Metadata } from "next";
import { ContactContent } from "./contact-content";

export const metadata: Metadata = {
  title: "Contact us",
  description:
    "Talk to the ETHR team about bringing workforce management to your Ethiopian organization.",
};

export default function ContactPage() {
  return <ContactContent />;
}
