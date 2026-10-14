"use client";

import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useApiReference,
  type ApiReference,
} from "@/features/api-reference/api";
import { EndpointList } from "@/features/api-reference/endpoint-list";
import { useT } from "@/lib/i18n/useT";

/**
 * The API reference for an organisation's own developers: how to call the API
 * with a key, the conventions every endpoint shares, webhooks, and every
 * operation a key can call. One page for every organisation — the operations
 * come from the server (`GET /api-keys/reference`), the prose from i18n.
 *
 * The full Swagger UI at /api/docs stays with the platform's super admins: it
 * maps the platform console too (docs/admin-guide.md).
 */
const ABILITIES = [
  "read",
  "write",
  "employees",
  "attendance",
  "leave",
  "payroll",
  "reports",
] as const;

const SIGNATURE_SAMPLE = `// Node.js
import crypto from "node:crypto";

function verify(rawBody, headers, secret) {
  const sentAt = headers["x-ethr-timestamp"] ?? "";
  const given = headers["x-ethr-signature-v2"] ?? "";
  const expected = "sha256=" + crypto.createHmac("sha256", secret)
    .update(sentAt + ".").update(rawBody).digest("hex");
  const fresh = Math.abs(Date.now() / 1000 - Number(sentAt)) <= 300;
  return fresh && given.length === expected.length &&
    crypto.timingSafeEqual(Buffer.from(given), Buffer.from(expected));
}

// verify(rawRequestBody, req.headers, process.env.ETHR_WEBHOOK_SECRET)`;

function Code({ children }: { children: string }) {
  return (
    <pre className="overflow-x-auto rounded-md border bg-muted/30 p-3 font-mono text-xs">
      <code>{children}</code>
    </pre>
  );
}

function Section({
  id,
  title,
  children,
}: {
  id: string;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <Card>
      <CardContent className="space-y-3 p-4 text-sm">
        <h2 id={id} className="text-base font-semibold text-foreground">
          {title}
        </h2>
        {children}
      </CardContent>
    </Card>
  );
}

function Guide({ reference }: { reference: ApiReference }) {
  const { t } = useT();
  const base = reference.servers?.[0]?.url ?? "/api/v1";
  const abilityText: Record<(typeof ABILITIES)[number], string> = {
    read: t("api_reference.ability_read"),
    write: t("api_reference.ability_write"),
    employees: t("api_reference.ability_employees"),
    attendance: t("api_reference.ability_attendance"),
    leave: t("api_reference.ability_leave"),
    payroll: t("api_reference.ability_payroll"),
    reports: t("api_reference.ability_reports"),
  };

  return (
    <div className="space-y-4">
      <Section id="getting-started" title={t("api_reference.start_title")}>
        <p className="text-muted-foreground">{t("api_reference.start_body")}</p>
        <Code>{`curl ${base}/employees?per_page=1 \\
  -H "Authorization: Bearer ethr_…" \\
  -H "Accept: application/json"`}</Code>
        <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
          <li>{t("api_reference.start_acts_as")}</li>
          <li>{t("api_reference.start_tenant")}</li>
          <li>{t("api_reference.start_secret")}</li>
        </ul>
      </Section>

      <Section id="abilities" title={t("api_reference.abilities_title")}>
        <ul className="space-y-1">
          {ABILITIES.map((a) => (
            <li key={a}>
              <code className="font-mono text-xs font-semibold">{a}</code>
              <span className="text-muted-foreground">
                {": "}
                {abilityText[a]}
              </span>
            </li>
          ))}
        </ul>
        <p className="text-muted-foreground">
          {t("api_reference.session_only")}
        </p>
      </Section>

      <Section id="conventions" title={t("api_reference.conventions_title")}>
        <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
          <li>{t("api_reference.conv_pagination")}</li>
          <li>{t("api_reference.conv_filter")}</li>
          <li>{t("api_reference.conv_ids")}</li>
          <li>{t("api_reference.conv_idempotency")}</li>
          <li>{t("api_reference.conv_errors")}</li>
          <li>{t("api_reference.conv_rate")}</li>
        </ul>
        <Code>{`{
  "type": "https://ethr.et/errors/api-key-ability",
  "title": "API Key Lacks Ability",
  "status": 403,
  "detail": "This API key needs one of these abilities: write, employees."
}`}</Code>
      </Section>

      <Section id="webhooks" title={t("api_reference.webhooks_title")}>
        <p className="text-muted-foreground">
          {t("api_reference.webhooks_body")}
        </p>
        <div className="flex flex-wrap gap-1">
          {reference["x-webhook-events"].map((e) => (
            <code
              key={e}
              className="rounded border px-1.5 py-0.5 font-mono text-xs"
            >
              {e}
            </code>
          ))}
        </div>
        <Code>{`POST <your URL>
X-ETHR-Event: employee.created
X-ETHR-Delivery: 01J…
X-ETHR-Timestamp: 1760436000
X-ETHR-Signature-V2: sha256=<hex>
X-ETHR-Signature: sha256=<hex>

{
  "event": "employee.created",
  "timestamp": "2026-10-14T09:00:00+00:00",
  "tenant_id": "01J…",
  "data": { "public_id": "01J…", "name": "…" }
}`}</Code>
        <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
          <li>{t("api_reference.webhooks_verify")}</li>
          <li>{t("api_reference.webhooks_retry")}</li>
          <li>{t("api_reference.webhooks_replay")}</li>
        </ul>
        <Code>{SIGNATURE_SAMPLE}</Code>
      </Section>

      <Section id="endpoints" title={t("api_reference.endpoints_title")}>
        <p className="text-muted-foreground">
          {t("api_reference.endpoints_body")}
        </p>
        <EndpointList reference={reference} />
      </Section>
    </div>
  );
}

export default function ApiReferencePage() {
  const { t } = useT();
  const query = useApiReference();

  return (
    <RoleGate anyPermission={["manageApiKeys"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("api_reference.title")}
          description={t("api_reference.description")}
          actions={
            <Button variant="outline" asChild>
              <Link href="/settings/api-keys">
                <ArrowLeft className="mr-2 h-4 w-4" aria-hidden="true" />{" "}
                {t("api_keys_page.title")}
              </Link>
            </Button>
          }
        />
        <QueryBoundary
          query={query}
          loading={
            <div className="space-y-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-24 w-full" />
              ))}
            </div>
          }
          isEmpty={(reference) => Object.keys(reference.paths).length === 0}
          empty={
            <p className="text-sm text-muted-foreground">
              {t("api_reference.no_match")}
            </p>
          }
        >
          {(reference) => <Guide reference={reference} />}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}
