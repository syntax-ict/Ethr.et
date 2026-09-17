import type { Metadata } from "next";
import { ContactContent } from "./contact-content";
import { marketingMetadata } from "../page-metadata";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/contact", "contact", {
    title: "Contact us",
    description:
      "Talk to the ETHR team about bringing workforce management to your Ethiopian organization.",
  });
}

export default function ContactPage() {
  return <ContactContent />;
}
