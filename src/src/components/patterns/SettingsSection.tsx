import * as React from "react";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { HowItWorks } from "@/components/patterns/SettingRow";
import { cn } from "@/lib/utils";

interface SettingsSectionProps {
  title: React.ReactNode;
  icon?: React.ReactNode;
  /** One sentence, two lines at most. */
  intro?: React.ReactNode;
  /** What does not fit in the intro, behind a closed "How it works". */
  details?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  /** Classes for the rows' container; rows stack with a gap by default. */
  contentClassName?: string;
}

/**
 * A group of settings: a card with an `h2` title, a one-line intro and
 * `SettingRow` children. It has no save of its own. A page keeps its one save
 * and its `save-status` announcement, so a section is only the grouping.
 */
export function SettingsSection({
  title,
  icon,
  intro,
  details,
  children,
  className,
  contentClassName,
}: SettingsSectionProps) {
  return (
    <Card className={className}>
      <CardHeader>
        <h2 className="flex items-center gap-2 text-heading text-foreground">
          {icon}
          {title}
        </h2>
        {intro && <p className="text-sm text-muted-foreground">{intro}</p>}
        {details && <HowItWorks>{details}</HowItWorks>}
      </CardHeader>
      <CardContent className={cn("space-y-4", contentClassName)}>
        {children}
      </CardContent>
    </Card>
  );
}
