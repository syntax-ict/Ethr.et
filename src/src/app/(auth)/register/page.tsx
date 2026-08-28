import type { Metadata } from "next";
import { RegisterForm } from "./register-form";

export const metadata: Metadata = {
  title: "Create your workspace",
  description:
    "Start your free ETHR trial and set up your organization in minutes.",
};

export default function Page() {
  return <RegisterForm />;
}
