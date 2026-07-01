import type { Metadata } from "next";
import { SetupWizard } from "@/features/onboarding/components/setup-wizard";

export const metadata: Metadata = {
  title: "Setup",
};

export default function SetupPage() {
  // The wizard owns its own header/welcome; we just provide outer padding.
  return (
    <div className="py-6">
      <SetupWizard />
    </div>
  );
}
