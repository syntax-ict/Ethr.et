"use client";

import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useT } from "@/lib/i18n/useT";

/**
 * Marks an employee as outside the organisation's employment law: a managerial
 * employee of a private company, or staff under a special law. ETHR then
 * answers "no framework" for the person instead of guessing. The note is for
 * the reader who comes after, and is kept only while the box is ticked.
 */
export function FrameworkExceptionField({
  idPrefix,
  outside,
  note,
  onOutsideChange,
  onNoteChange,
  className,
}: {
  idPrefix: string;
  outside: boolean;
  note: string;
  onOutsideChange: (outside: boolean) => void;
  onNoteChange: (note: string) => void;
  className?: string;
}) {
  const { t } = useT();

  return (
    <div
      className={`flex items-start gap-3 rounded-md border border-border-default p-4 ${className ?? ""}`}
    >
      <Checkbox
        id={`${idPrefix}-outside`}
        checked={outside}
        onCheckedChange={(v) => onOutsideChange(v === true)}
        aria-describedby={`${idPrefix}-outside-hint`}
      />
      <div className="flex-1 space-y-1">
        <Label htmlFor={`${idPrefix}-outside`} className="text-sm font-medium">
          {t(
            "employee.outside_framework",
            "Not covered by the organisation's employment law",
          )}
        </Label>
        <p
          id={`${idPrefix}-outside-hint`}
          className="text-xs text-muted-foreground"
        >
          {t(
            "employee.outside_framework_help",
            "For example a managerial employee, or staff under a special law. ETHR will not apply the organisation's employment rules to this person.",
          )}
        </p>
        {outside && (
          <div className="pt-2">
            <Label htmlFor={`${idPrefix}-note`} className="text-xs">
              {t("employee.outside_framework_note", "Note (optional)")}
            </Label>
            <Input
              id={`${idPrefix}-note`}
              value={note}
              maxLength={255}
              onChange={(e) => onNoteChange(e.target.value)}
              className="mt-1"
              autoComplete="off"
            />
          </div>
        )}
      </div>
    </div>
  );
}
