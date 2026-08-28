import type { Metadata } from "next";
import { GuidedOnboarding } from "@/features/onboarding/v2/components/guided-onboarding";

export const metadata: Metadata = {
  title: "Guided Setup",
};

export default function GuidedSetupPage() {
  return (
    <div className="py-6">
      <GuidedOnboarding />
    </div>
  );
}
