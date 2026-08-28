"use client";

import { useState } from "react";
import { KeyRound, Loader2, Mail, Phone, IdCard, AtSign } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { useAccessPolicy, useUpdateAccessPolicy } from "../api";
import type { LoginIdentifier } from "../types";

const META: Record<
  LoginIdentifier,
  { icon: typeof Mail; key: string; label: string; desc: string }
> = {
  email: {
    icon: Mail,
    key: "setup2.id_email",
    label: "Email address",
    desc: "Standard for staff with company email.",
  },
  phone: {
    icon: Phone,
    key: "setup2.id_phone",
    label: "Mobile number",
    desc: "For workforces without email.",
  },
  employee_code: {
    icon: IdCard,
    key: "setup2.id_code",
    label: "Employee number",
    desc: "Field staff and contractors.",
  },
  username: {
    icon: AtSign,
    key: "setup2.id_username",
    label: "Username",
    desc: "A chosen handle, for teams that prefer it.",
  },
};

export function AccessStep({ onSaved }: { onSaved?: () => void }) {
  const { t } = useT();
  const policyQuery = useAccessPolicy();
  const update = useUpdateAccessPolicy();
  const [selected, setSelected] = useState<LoginIdentifier[] | null>(null);

  // Seed once from the loaded policy, without clobbering an in-progress edit.
  // Adjusting state during render avoids an extra effect commit — see
  // https://react.dev/learn/you-might-not-need-an-effect.
  if (policyQuery.data && selected === null) {
    setSelected(policyQuery.data.login_identifiers);
  }

  function toggle(id: LoginIdentifier) {
    setSelected((prev) => {
      const set = new Set(prev ?? []);
      if (set.has(id)) {
        set.delete(id);
      } else {
        set.add(id);
      }
      return Array.from(set);
    });
  }

  async function save() {
    if (!selected || selected.length === 0) {
      toast.error(
        t("setup2.pick_one_identifier", "Choose at least one login method"),
      );
      return;
    }
    try {
      await update.mutateAsync({ login_identifiers: selected });
      toast.success(t("setup2.access_saved", "Login methods saved"));
      onSaved?.();
    } catch {
      toast.error(t("setup2.save_failed", "Save failed"));
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <KeyRound className="h-5 w-5 text-primary" />
        </div>
        <div>
          <h2 className="text-xl font-bold text-foreground">
            {t("setup2.access_title", "How employees log in")}
          </h2>
          <p className="text-sm text-muted-foreground">
            {t(
              "setup2.access_desc",
              "Pick the identifiers your team can use. Enable more than one to mix email and mobile logins.",
            )}
          </p>
        </div>
      </div>

      <QueryBoundary query={policyQuery}>
        {(policy) => (
          <div className="space-y-3">
            {policy.available.map((id) => {
              const meta = META[id];
              const Icon = meta.icon;
              const checked = (selected ?? []).includes(id);
              return (
                <Card
                  key={id}
                  className={checked ? "border-primary" : undefined}
                >
                  <CardContent className="flex items-center gap-3 p-4">
                    <Checkbox
                      id={`id-${id}`}
                      checked={checked}
                      onCheckedChange={() => toggle(id)}
                    />
                    <Icon className="h-5 w-5 text-muted-foreground" />
                    <label
                      htmlFor={`id-${id}`}
                      className="flex-1 cursor-pointer"
                    >
                      <span className="block text-sm font-medium text-foreground">
                        {t(meta.key, meta.label)}
                      </span>
                      <span className="block text-xs text-muted-foreground">
                        {t(`${meta.key}_desc`, meta.desc)}
                      </span>
                    </label>
                  </CardContent>
                </Card>
              );
            })}

            <div className="flex justify-end border-t pt-4">
              <Button onClick={save} disabled={update.isPending}>
                {update.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : null}
                {t("setup2.save_methods", "Save login methods")}
              </Button>
            </div>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
}
