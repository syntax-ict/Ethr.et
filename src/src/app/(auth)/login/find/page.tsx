import type { Metadata } from "next";
import { FindOrganisationForm } from "./find-organisation-form";

export const metadata: Metadata = {
  title: "Find your organisation",
  description: "Get your ETHR organisation's sign-in link by email.",
};

export default function Page() {
  return <FindOrganisationForm />;
}
