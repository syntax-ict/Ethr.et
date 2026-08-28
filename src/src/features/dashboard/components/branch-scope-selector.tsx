"use client";

import { Building2, MapPin } from "lucide-react";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useBranchList } from "@/features/dashboard/executive-api";
import { useT } from "@/lib/i18n/useT";

const ALL_BRANCHES = "__all__";

/**
 * Lets a CEO / HR Director / Finance Director persona (`dashboard.executive`)
 * drill from "all branches" into one; a Regional Manager / Operations persona
 * (`dashboard.regional`-only) is server-forced onto their own branch, so they
 * get a plain label instead of a picker — the server ignores any branch they
 * send, and offering a control that does nothing would be worse than none.
 */
export function BranchScopeSelector({
  value,
  onChange,
}: {
  value: string | undefined;
  onChange: (branchPublicId: string | undefined) => void;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const { data } = useBranchList(can.viewExecutiveDashboard);

  if (!can.viewExecutiveDashboard) {
    return can.viewRegionalDashboard ? (
      <div className="flex items-center gap-1.5 rounded-md border border-border bg-muted/40 px-3 py-1.5 text-sm text-muted-foreground">
        <MapPin className="h-3.5 w-3.5" />
        {t("executive_dashboard.your_branch", "Your branch")}
      </div>
    ) : null;
  }

  const branches = data?.branches ?? [];

  return (
    <Select
      value={value ?? ALL_BRANCHES}
      onValueChange={(v) => onChange(v === ALL_BRANCHES ? undefined : v)}
    >
      {/* This selector sits in a toolbar with no visible label, so it needs its
          own name — the icon is decorative and SelectValue only renders the
          current value, which announces as a bare branch name with no
          indication of what it controls. */}
      <SelectTrigger
        className="w-full sm:w-56"
        aria-label={t("executive_dashboard.branch_scope", "Filter by branch")}
      >
        <Building2
          className="mr-1.5 h-3.5 w-3.5 text-muted-foreground"
          aria-hidden="true"
        />
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={ALL_BRANCHES}>
          {t("executive_dashboard.all_branches", "All branches")}
        </SelectItem>
        {branches.map((b) => (
          <SelectItem key={b.public_id} value={b.public_id}>
            {b.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
