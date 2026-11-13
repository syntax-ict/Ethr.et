"use client";

import Link from "next/link";
import {
  BadgeHelp,
  ChevronDown,
  FileSpreadsheet,
  HardDrive,
  Plus,
  UserRound,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  useHasReadableDevice,
  useUnmatchedBadges,
} from "@/features/devices/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * Every way there is to add someone, from the one place people look for it.
 *
 * ETHR already had four (one person, a file or pasted rows, an attendance
 * terminal's enrolled users, and the badges a terminal sent that nobody owns),
 * but only the first was offered from the Employees screens. This adds no
 * path: each item opens a page that already exists.
 *
 * `split` keeps the common case one click: the main half opens the form, the
 * caret opens the others. `others` is for the form itself, where "one person"
 * is the page already open.
 *
 * The device rows are asked for only by someone who may list devices, so a
 * person who can add employees but not see devices does not fire two refused
 * requests on every load.
 */
export function AddEmployeeMenu({
  variant = "split",
}: {
  variant?: "split" | "others";
}) {
  const { t } = useT();
  const { can } = usePermissions();
  // A terminal that only sends to ETHR cannot be asked who is enrolled on it,
  // so it does not make "from an attendance terminal" a way in.
  const readableQuery = useHasReadableDevice(can.viewDevices);
  const badgesQuery = useUnmatchedBadges(
    can.viewDevices && can.manageAttendance,
  );

  const readableDevice = can.viewDevices && readableQuery.data === true;
  const badges =
    can.viewDevices && can.manageAttendance
      ? (badgesQuery.data?.length ?? 0)
      : 0;

  const items = (
    <DropdownMenuContent align="end" className="w-64">
      {variant === "split" && (
        <DropdownMenuItem asChild>
          <Link href="/employees/new">
            <UserRound className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("employees.add_menu.one", "One person")}
          </Link>
        </DropdownMenuItem>
      )}
      <DropdownMenuItem asChild>
        <Link href="/employees/import">
          <FileSpreadsheet className="mr-2 h-4 w-4" aria-hidden="true" />
          {t("employees.add_menu.file", "From a file or pasted rows")}
        </Link>
      </DropdownMenuItem>
      {readableDevice && (
        <DropdownMenuItem asChild>
          <Link href="/employees/from-device">
            <HardDrive className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("employees.add_menu.device", "From an attendance terminal")}
          </Link>
        </DropdownMenuItem>
      )}
      {badges > 0 && (
        <DropdownMenuItem asChild>
          <Link href="/devices#unmatched">
            <BadgeHelp className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("employees.add_menu.badges", "Unrecognised badges (:count)", {
              count: badges,
            })}
          </Link>
        </DropdownMenuItem>
      )}
    </DropdownMenuContent>
  );

  if (variant === "others") {
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm">
            {t("employees.add_menu.other_ways", "Other ways to add")}
            <ChevronDown className="ml-2 h-4 w-4" aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        {items}
      </DropdownMenu>
    );
  }

  return (
    <div className="inline-flex">
      <Button asChild className="rounded-r-none">
        <Link href="/employees/new">
          <Plus className="mr-2 h-4 w-4" />
          {t("employees.add", "Add Employee")}
        </Link>
      </Button>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            size="icon"
            className="rounded-l-none border-l border-primary-foreground/20"
            aria-label={t("employees.add_menu.more", "More ways to add")}
          >
            <ChevronDown className="h-4 w-4" aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        {items}
      </DropdownMenu>
    </div>
  );
}
