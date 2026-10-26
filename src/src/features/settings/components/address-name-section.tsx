"use client";

import { useState } from "react";
import { Check, Info, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useDebounce } from "@/lib/hooks/useDebounce";
import { useT } from "@/lib/i18n/useT";
import {
  useSubdomainCheck,
  useUpdateSubdomain,
  type TenantAddress,
} from "@/features/settings/api";

/** Registration's own format (Tenant::SUBDOMAIN_REGEX), checked as the admin types. */
const NAME_FORMAT = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

/** What the admin typed, as the server will read it. */
function normalise(value: string): string {
  return value.trim().toLowerCase();
}

/** An address without its scheme, as people say it: `ethr.et/acme`. */
function bare(url: string): string {
  return url.replace(/^https?:\/\//, "").replace(/\/$/, "");
}

/**
 * The organisation's address name: the `{name}` in ethr.et/{name} and
 * {name}.ethr.et. A tenant admin can change it.
 *
 * The old name is retired, not released: it keeps working and forwards to
 * the new one, and stays reserved for the organisation, which can take it
 * back. The name is checked as it is typed, by the same rules the server
 * holds a rename to, and the change is confirmed in a dialog that says what
 * happens before anything is sent.
 *
 * After a rename this browser sends the new name from then on. On the old
 * subdomain itself it goes on to the new one, where the person signs in
 * again, because a session belongs to the host it was made on.
 */
export function AddressNameSection({ address }: { address: TenantAddress }) {
  const { t } = useT();
  const rename = useUpdateSubdomain();
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(address.subdomain);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);

  const name = normalise(draft);
  const changed = name !== address.subdomain;
  const wellFormed =
    name.length >= 3 && name.length <= 63 && NAME_FORMAT.test(name);
  const debounced = useDebounce(name, 400);
  const check = useSubdomainCheck(
    debounced,
    editing && changed && wellFormed && debounced === name,
  );

  const formatError =
    changed && name.length > 0 && !wellFormed
      ? name.length < 3
        ? t("settings.address_name_too_short", "Use at least 3 characters.")
        : t(
            "settings.address_name_format",
            "Use lower-case letters, numbers and hyphens, starting and ending with a letter or number.",
          )
      : null;
  const checked = check.data?.subdomain === name ? check.data : undefined;
  const fieldError =
    serverError ??
    formatError ??
    (checked && !checked.available ? checked.message : null);
  const ready = changed && wellFormed && checked?.available === true;

  // The address shown with the new name, before and after it: the shared
  // address always, and the subdomain where this installation serves them.
  const entryPrefix = address.entry_url.slice(
    0,
    address.entry_url.length - address.subdomain.length,
  );
  const newEntry = bare(entryPrefix + name);
  const oldEntry = bare(address.entry_url);
  const subdomainHost = address.subdomain_url
    ? bare(address.subdomain_url).slice(address.subdomain.length)
    : null;
  const onOwnSubdomain = address.effective_mode === "subdomain";
  // An API from before retired names existed sends none: an empty list.
  const retired = address.retired_subdomains ?? [];

  function startEditing() {
    setDraft(address.subdomain);
    setServerError(null);
    setEditing(true);
  }

  function cancel() {
    setEditing(false);
    setDraft(address.subdomain);
    setServerError(null);
  }

  function submit() {
    const previous = address.subdomain;
    rename.mutate(name, {
      onSuccess: (data) => {
        const next = data.address;
        try {
          if (localStorage.getItem("tenant") === previous) {
            localStorage.setItem("tenant", next.subdomain);
          }
        } catch {
          // Storage may be unavailable; the old name still resolves.
        }
        setConfirmOpen(false);
        setEditing(false);
        toast.success(
          t("settings.address_name_saved", "Address changed to :address", {
            address: bare(next.canonical_url ?? next.entry_url),
          }),
        );

        // On the old subdomain itself, go on to the new one.
        if (window.location.hostname.startsWith(`${previous}.`)) {
          window.location.assign(
            `${next.canonical_url ?? next.subdomain_url ?? next.entry_url}/login`,
          );
        }
      },
      onError: (err: unknown) => {
        setConfirmOpen(false);
        const data = (
          err as {
            response?: {
              data?: { detail?: string; errors?: { subdomain?: string[] } };
            };
          }
        ).response?.data;
        const message = data?.errors?.subdomain?.[0];
        if (message) {
          setServerError(message);
          return;
        }
        toast.error(
          data?.detail ||
            t("settings.address_name_failed", "Couldn't change the address"),
        );
      },
    });
  }

  return (
    <div className="space-y-3 rounded-md border p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="space-y-1">
          <p className="text-sm font-medium text-foreground">
            {t("settings.address_name_title", "Address name")}
          </p>
          <p className="text-xs text-muted-foreground">
            {t(
              "settings.address_name_help",
              "The name in your organisation's address. Changing it keeps the old address working.",
            )}
          </p>
        </div>
        {!editing && (
          <Button variant="outline" size="sm" onClick={startEditing}>
            {t("settings.address_name_change", "Change name")}
          </Button>
        )}
      </div>

      {!editing ? (
        <code
          className="block break-all font-mono text-sm"
          data-testid="address-name"
        >
          {oldEntry}
        </code>
      ) : (
        <div className="space-y-2">
          <Label htmlFor="address-name-input">
            {t("settings.address_name_label", "New name")}
          </Label>
          <div className="flex items-stretch overflow-hidden rounded-md border focus-within:ring-2 focus-within:ring-ring">
            <span className="flex items-center bg-muted px-3 font-mono text-xs text-muted-foreground">
              {bare(entryPrefix)}/
            </span>
            <Input
              id="address-name-input"
              value={draft}
              onChange={(e) => {
                setDraft(e.target.value.replace(/\s+/g, "").toLowerCase());
                setServerError(null);
              }}
              maxLength={63}
              autoComplete="off"
              autoCapitalize="none"
              spellCheck={false}
              className="rounded-none border-0 font-mono focus-visible:ring-0"
              aria-invalid={fieldError ? true : undefined}
              aria-describedby="address-name-status"
            />
          </div>
          <div
            id="address-name-status"
            className="min-h-4 text-xs"
            aria-live="polite"
          >
            {fieldError ? (
              <p role="alert" className="text-destructive">
                {fieldError}
              </p>
            ) : changed && wellFormed && (check.isFetching || !checked) ? (
              <p className="flex items-center gap-1 text-muted-foreground">
                <Loader2 className="h-3 w-3 animate-spin" aria-hidden />
                {t("settings.address_name_checking", "Checking")}
              </p>
            ) : ready ? (
              <p className="flex items-center gap-1 text-success">
                <Check className="h-3 w-3" aria-hidden />
                {t("settings.address_name_available", "Available")}
              </p>
            ) : null}
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="outline" size="sm" onClick={cancel}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button
              size="sm"
              onClick={() => setConfirmOpen(true)}
              disabled={!ready}
            >
              {t("settings.address_name_continue", "Continue")}
            </Button>
          </div>
        </div>
      )}

      {retired.length > 0 && (
        <p
          className="text-xs text-muted-foreground"
          data-testid="address-retired"
        >
          {t(
            "settings.address_name_retired",
            "Earlier names that still forward here: :names",
            { names: retired.join(", ") },
          )}
        </p>
      )}

      <Dialog
        open={confirmOpen}
        onOpenChange={(open) => !rename.isPending && setConfirmOpen(open)}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {t(
                "settings.address_name_confirm_title",
                "Change your address to :address?",
                {
                  address: newEntry,
                },
              )}
            </DialogTitle>
            <DialogDescription>
              {t(
                "settings.address_name_confirm_description",
                "Everyone in your organisation uses the new address from now on.",
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="rounded-lg border bg-muted/40 p-3">
            <div className="flex items-start gap-2">
              <Info
                className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden
              />
              <ul className="space-y-1.5 text-sm text-foreground">
                <li>
                  {t(
                    "settings.address_name_effect_forward",
                    ":old keeps working and forwards to :new. The old name stays reserved for your organisation.",
                    { old: oldEntry, new: newEntry },
                  )}
                </li>
                {subdomainHost && (
                  <li>
                    {t(
                      "settings.address_name_effect_subdomain",
                      "Your subdomain becomes :host.",
                      { host: `${name}${subdomainHost}` },
                    )}
                  </li>
                )}
                {onOwnSubdomain && (
                  <li>
                    {t(
                      "settings.address_name_effect_signin",
                      "People signed in at the old subdomain sign in again at the new one.",
                    )}
                  </li>
                )}
                <li>
                  {t(
                    "settings.address_name_effect_sso",
                    "If you sign in with SSO, give your identity provider the new addresses shown in the SSO settings.",
                  )}
                </li>
              </ul>
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setConfirmOpen(false)}
              disabled={rename.isPending}
            >
              {t("common.cancel", "Cancel")}
            </Button>
            <Button onClick={submit} disabled={rename.isPending}>
              {rename.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("settings.address_name_confirm", "Change address")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
