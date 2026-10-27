"use client";

import { useState } from "react";
import { Globe, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { DnsRecord } from "@/components/shared/dns-record";
import { useT } from "@/lib/i18n/useT";
import {
  useUpdateAddress,
  useVerifyAddress,
  type TenantAddress,
} from "@/features/settings/api";
import { AddressNameSection } from "./address-name-section";

/**
 * How the organisation is reached: the shared address (ethr.et/{slug}), its
 * own subdomain, or its own domain. The choice decides every link ETHR sends.
 *
 * The server reports what it can honour: `subdomains_served` says whether this
 * installation serves subdomains at all, `custom_domain_allowed` whether the
 * plan includes the add-on, and `effective_mode` what links use right now. A
 * choice that cannot be honoured yet is kept — the organisation does not have
 * to come back once the host work is done — and the card says which address
 * is used in the meantime.
 */
type Mode = "path" | "subdomain" | "custom_domain";

const MODES: Mode[] = ["path", "subdomain", "custom_domain"];

export interface AddressCardProps {
  address?: TenantAddress | null;
}

export function AddressCard({ address }: AddressCardProps) {
  const { t } = useT();
  const updateAddress = useUpdateAddress();
  const verifyAddress = useVerifyAddress();

  const currentMode = (address?.mode ?? "path") as Mode;
  const [mode, setMode] = useState<Mode>(currentMode);
  const [domain, setDomain] = useState(address?.custom_domain ?? "");
  const [domainError, setDomainError] = useState<string | null>(null);
  const [verifyErrors, setVerifyErrors] = useState<string[]>([]);

  if (!address) return null;

  const domainChanged = domain.trim() !== (address.custom_domain ?? "");
  const dirty =
    mode !== currentMode || (mode === "custom_domain" && domainChanged);

  const modeLabels: Record<Mode, string> = {
    path: t("settings.address_mode_path", "Shared address"),
    subdomain: t("settings.address_mode_subdomain", "Own subdomain"),
    custom_domain: t("settings.address_mode_custom", "Own domain"),
  };

  const modeHelp: Record<Mode, string> = {
    path: t(
      "settings.address_mode_path_help",
      "On ETHR's own site. Works everywhere, with no setup.",
    ),
    subdomain: t(
      "settings.address_mode_subdomain_help",
      "Your organisation's name in front of ETHR's domain.",
    ),
    custom_domain: t(
      "settings.address_mode_custom_help",
      "A domain you already own, such as hr.example.com. Enterprise add-on.",
    ),
  };

  const modeAddress: Record<Mode, string | null> = {
    path: address.entry_url,
    subdomain: address.subdomain_url,
    custom_domain: address.custom_domain,
  };

  function save() {
    setDomainError(null);
    const payload: Parameters<typeof updateAddress.mutate>[0] = { mode };
    if (mode === "custom_domain" && domainChanged) {
      const trimmed = domain.trim();
      payload.custom_domain = trimmed === "" ? null : trimmed;
    }

    updateAddress.mutate(payload, {
      onSuccess: () =>
        toast.success(t("settings.address_saved", "Address updated")),
      // A field error belongs under the field; anything else carries its
      // reason in `detail`.
      onError: (err: unknown) => {
        const data = (
          err as {
            response?: {
              data?: {
                detail?: string;
                errors?: { custom_domain?: string[]; mode?: string[] };
              };
            };
          }
        ).response?.data;
        const fieldError = data?.errors?.custom_domain?.[0];
        if (fieldError) {
          setDomainError(fieldError);
        } else {
          toast.error(
            data?.errors?.mode?.[0] ||
              data?.detail ||
              t("settings.address_save_failed", "Failed to update address"),
          );
        }
      },
    });
  }

  // The server looks the records up; a 422 names each it did not find
  // (`errors.txt`, `errors.cname`), listed under the records.
  function verify() {
    setVerifyErrors([]);
    verifyAddress.mutate(undefined, {
      onSuccess: () =>
        toast.success(t("settings.address_verified_toast", "Domain verified")),
      onError: (err: unknown) => {
        const data = (
          err as {
            response?: {
              data?: { detail?: string; errors?: Record<string, string[]> };
            };
          }
        ).response?.data;
        const messages = Object.values(data?.errors ?? {}).flat();
        if (messages.length > 0) {
          setVerifyErrors(messages);
        } else {
          toast.error(
            data?.detail ||
              t("settings.address_verify_failed", "Verification failed"),
          );
        }
      },
    });
  }

  const currentUrl = address.canonical_url ?? address.entry_url;
  const chosenNotInUse = address.mode !== address.effective_mode;

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Globe className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-base">
            {t("settings.address_title", "Address")}
          </CardTitle>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className="text-sm text-muted-foreground">
          {t(
            "settings.address_intro",
            "How your organisation is reached. Every link ETHR sends points here.",
          )}
        </p>

        <div className="rounded-md border p-3 text-sm">
          <span className="text-muted-foreground">
            {t("settings.address_current", "Current address")}
          </span>{" "}
          <code className="break-all font-mono" data-testid="current-address">
            {currentUrl}
          </code>
          {chosenNotInUse && (
            <p className="mt-1 text-xs text-muted-foreground">
              {t(
                "settings.address_not_served",
                "Not available on this installation yet; the shared address is used until it is.",
              )}
            </p>
          )}
        </div>

        <AddressNameSection address={address} />

        <fieldset className="space-y-3">
          <legend className="sr-only">
            {t("settings.address_title", "Address")}
          </legend>
          {MODES.map((option) => {
            const subdomainUnavailable =
              option === "subdomain" && !address.subdomains_served;
            const customNotInPlan =
              option === "custom_domain" && !address.custom_domain_allowed;
            const disabled = customNotInPlan;

            return (
              <label
                key={option}
                className={`flex cursor-pointer items-start gap-3 rounded-md border p-3 ${
                  mode === option ? "border-primary" : ""
                } ${disabled ? "cursor-not-allowed opacity-60" : ""}`}
              >
                <input
                  type="radio"
                  name="address-mode"
                  value={option}
                  checked={mode === option}
                  disabled={disabled}
                  onChange={() => setMode(option)}
                  className="mt-1"
                  aria-label={modeLabels[option]}
                />
                <span className="flex-1 space-y-1">
                  <span className="flex flex-wrap items-center gap-2 text-sm font-medium text-foreground">
                    {modeLabels[option]}
                    {modeAddress[option] && (
                      <code className="break-all font-mono text-xs text-muted-foreground">
                        {modeAddress[option]}
                      </code>
                    )}
                    {option === "custom_domain" &&
                      address.custom_domain_status === "verified" && (
                        <Badge variant="success">
                          {t("settings.address_verified", "Verified")}
                        </Badge>
                      )}
                    {option === "custom_domain" &&
                      address.custom_domain_status === "pending" && (
                        <Badge variant="warning">
                          {t(
                            "settings.address_pending",
                            "Pending verification",
                          )}
                        </Badge>
                      )}
                  </span>
                  <span className="block text-xs text-muted-foreground">
                    {modeHelp[option]}
                  </span>
                  {subdomainUnavailable && (
                    <span className="block text-xs text-warning">
                      {t(
                        "settings.address_not_served",
                        "Not available on this installation yet; the shared address is used until it is.",
                      )}
                    </span>
                  )}
                  {customNotInPlan && (
                    <span className="block text-xs text-muted-foreground">
                      {t(
                        "settings.address_custom_not_in_plan",
                        "Your plan does not include a custom domain.",
                      )}
                    </span>
                  )}
                </span>
              </label>
            );
          })}
        </fieldset>

        {mode === "custom_domain" && address.custom_domain_allowed && (
          <div>
            <Label htmlFor="address-custom-domain">
              {t("settings.address_custom_domain_label", "Domain")}
            </Label>
            <Input
              id="address-custom-domain"
              value={domain}
              onChange={(e) => {
                setDomain(e.target.value);
                setDomainError(null);
              }}
              placeholder="hr.example.com"
              className="mt-1"
              aria-invalid={domainError ? true : undefined}
              aria-describedby={
                domainError ? "address-custom-domain-error" : undefined
              }
            />
            {domainError && (
              <p
                id="address-custom-domain-error"
                role="alert"
                className="mt-1 text-xs text-destructive"
              >
                {domainError}
              </p>
            )}
          </div>
        )}

        {address.custom_domain_status === "pending" &&
          address.custom_domain_dns &&
          !domainChanged && (
            <div className="space-y-3 rounded-md border p-3">
              <p className="text-sm text-muted-foreground">
                {t(
                  "settings.address_dns_intro",
                  "Add these two DNS records at your domain provider, then verify.",
                )}
              </p>
              <dl className="space-y-3">
                <DnsRecord
                  type="TXT"
                  name={address.custom_domain_dns.txt_name}
                  value={address.custom_domain_dns.txt_value}
                  nameLabel={t("settings.address_record_name", "Name")}
                  valueLabel={t("settings.address_record_value", "Value")}
                />
                <DnsRecord
                  type="CNAME"
                  name={address.custom_domain_dns.cname_name ?? ""}
                  value={address.custom_domain_dns.cname_target ?? "—"}
                  nameLabel={t("settings.address_record_name", "Name")}
                  valueLabel={t("settings.address_record_value", "Value")}
                />
              </dl>
              {verifyErrors.length > 0 && (
                <ul
                  role="alert"
                  className="list-disc space-y-1 pl-5 text-sm text-destructive"
                >
                  {verifyErrors.map((message) => (
                    <li key={message} className="break-words">
                      {message}
                    </li>
                  ))}
                </ul>
              )}
              <Button
                variant="outline"
                onClick={verify}
                disabled={verifyAddress.isPending}
              >
                {verifyAddress.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("settings.address_verify", "Verify domain")}
              </Button>
            </div>
          )}

        <div className="flex justify-end">
          <Button onClick={save} disabled={!dirty || updateAddress.isPending}>
            {updateAddress.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("common.save", "Save Changes")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
