"use client";

import { useEffect, useRef, useState } from "react";
import { Search, X } from "lucide-react";
import { Input } from "@/components/ui/input";
import { useT } from "@/lib/i18n/useT";

interface SearchInputProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  debounceMs?: number;
}

export function SearchInput({
  value,
  onChange,
  placeholder,
  debounceMs = 300,
}: SearchInputProps) {
  const { t } = useT();
  const [local, setLocal] = useState(value);

  // Emit only text the parent does not already have, and keep the latest
  // handler in a ref. The timer was re-armed whenever `onChange` changed
  // identity — every render, for the inline arrows every caller passes — and
  // fired the unchanged text. Callers reset to page 1 on a search, so Next on
  // the directory, employees, users and tenants lists bounced back to page 1
  // 300 ms later (audit N92).
  const onChangeRef = useRef(onChange);
  useEffect(() => {
    onChangeRef.current = onChange;
  });

  useEffect(() => {
    if (local === value) return;
    const timer = setTimeout(() => onChangeRef.current(local), debounceMs);
    return () => clearTimeout(timer);
  }, [local, value, debounceMs]);

  // Resync local state when the parent resets `value` from outside (e.g. a
  // "clear filters" action). Adjusting state during render avoids an extra
  // effect commit — see https://react.dev/learn/you-might-not-need-an-effect.
  const [prevValue, setPrevValue] = useState(value);
  if (value !== prevValue) {
    setPrevValue(value);
    setLocal(value);
  }

  return (
    <div className="relative">
      <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground/60" />
      <Input
        value={local}
        onChange={(e) => setLocal(e.target.value)}
        placeholder={placeholder ?? t("search_input.placeholder")}
        className="pl-9 pr-8"
      />
      {local && (
        <button
          type="button"
          onClick={() => {
            setLocal("");
            onChange("");
          }}
          className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground/60 transition-colors hover:text-foreground"
          aria-label={t("search_input.clear")}
        >
          <X className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  );
}
