import type { Metadata } from "next";
import { LoginForm } from "./login-form";

export const metadata: Metadata = {
  title: "Sign in",
  description: "Sign in to your ETHR workspace.",
};

export default function Page() {
  return <LoginForm />;
}
