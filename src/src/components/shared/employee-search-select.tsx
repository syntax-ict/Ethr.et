"use client";

import { useState } from "react";
import { Check, ChevronsUpDown, Loader2 } from "lucide-react";
import { ErrorState } from "@/components/shared/error-state";
import { Button } from "@/components/ui/button";
import {
  Command,
  CommandEmpty,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { useEmployees } from "@/features/employees/api";
import { useDebounce } from "@/lib/hooks/useDebounce";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/** Who was picked: the id the form sends and the name the button shows. */
export interface PickedEmployee {
  public_id: string;
  name: string;
}

/** Rows asked for per search. A name narrows the rest. */
export const EMPLOYEE_SEARCH_PAGE = 20;

/**
 * Choose an employee by searching the server, for a screen that shows many
 * pickers at once.
 *
 * `EmployeeSelect` loads every employee and renders each one as an option,
 * and a closed Radix Select still renders its options, into a detached
 * fragment, so it can name the chosen one. The Unrecognised badges card
 * showed one per badge: with 80 badges and 3,927 employees (Habru,
 * 2026-11-18) that was over 300,000 options and the tab stopped responding.
 * This one renders a button until it is opened, and only then asks the
 * server for one page of names.
 */
export function EmployeeSearchSelect({
  id,
  value,
  onChange,
}: {
  id?: string;
  value: PickedEmployee | null;
  onChange: (picked: PickedEmployee) => void;
}) {
  const { t } = useT();
  const [open, setOpen] = useState(false);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="mt-1 w-full justify-between px-3 font-normal"
        >
          <span className={cn("truncate", !value && "text-muted-foreground")}>
            {value?.name ?? t("attendance.select_employee", "Select employee")}
          </span>
          <ChevronsUpDown
            className="ml-2 h-4 w-4 shrink-0 opacity-50"
            aria-hidden="true"
          />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        className="w-[var(--radix-popover-trigger-width)] min-w-64 p-0"
      >
        <EmployeeSearchList
          selected={value?.public_id ?? null}
          onPick={(picked) => {
            onChange(picked);
            setOpen(false);
          }}
        />
      </PopoverContent>
    </Popover>
  );
}

/** Mounted only while the picker is open, so a closed one asks for nothing. */
function EmployeeSearchList({
  selected,
  onPick,
}: {
  selected: string | null;
  onPick: (picked: PickedEmployee) => void;
}) {
  const { t } = useT();
  const [term, setTerm] = useState("");
  const search = useDebounce(term.trim(), 250);
  const { data, isLoading, isError, refetch } = useEmployees({
    search: search || undefined,
    per_page: EMPLOYEE_SEARCH_PAGE,
    sort: "name",
  });
  const rows = data?.data ?? [];
  const more = (data?.meta?.total ?? 0) > rows.length;

  return (
    <Command shouldFilter={false}>
      <CommandInput
        value={term}
        onValueChange={setTerm}
        placeholder={t(
          "employees.search_placeholder",
          "Search by name, email, or code...",
        )}
      />
      <CommandList>
        {isError ? (
          <ErrorState compact onRetry={() => refetch()} />
        ) : isLoading ? (
          <div className="flex justify-center py-6">
            <Loader2
              className="h-4 w-4 animate-spin text-muted-foreground"
              aria-hidden="true"
            />
          </div>
        ) : (
          <CommandEmpty>
            {t("common.no_results", "No results found")}
          </CommandEmpty>
        )}
        {rows.map((e) => (
          <CommandItem
            key={e.public_id}
            value={e.public_id}
            onSelect={() => onPick({ public_id: e.public_id, name: e.name })}
          >
            <Check
              className={cn(
                "h-4 w-4",
                selected === e.public_id ? "opacity-100" : "opacity-0",
              )}
              aria-hidden="true"
            />
            <span className="truncate">{e.name}</span>
            {e.employee_code && (
              <>
                {/* So the option reads "name code", not "namecode". */}{" "}
                <span className="ml-auto text-xs text-muted-foreground">
                  {e.employee_code}
                </span>
              </>
            )}
          </CommandItem>
        ))}
        {!isLoading && !isError && more && (
          <p className="px-3 py-2 text-xs text-muted-foreground">
            {t(
              "employee_search.more",
              "Showing the first :count. Type a name or number to find anyone else.",
              { count: rows.length },
            )}
          </p>
        )}
      </CommandList>
    </Command>
  );
}
