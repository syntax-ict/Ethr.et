"use client";

import { useMemo, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { useT } from "@/lib/i18n/useT";
import {
  endpointsByTag,
  resolveRef,
  type ApiEndpoint,
  type ApiReference,
  type JsonSchema,
} from "./api";
import { SchemaView, typeLabel } from "./schema-view";

const METHOD_VARIANT = {
  GET: "info",
  POST: "success",
  PUT: "warning",
  PATCH: "warning",
  DELETE: "destructive",
} as const;

function firstSchema(
  content: Record<string, { schema?: JsonSchema }> | undefined,
): JsonSchema | undefined {
  return content ? Object.values(content)[0]?.schema : undefined;
}

/** Every operation a key can call, grouped by area and searchable. */
export function EndpointList({ reference }: { reference: ApiReference }) {
  const { t } = useT();
  const [query, setQuery] = useState("");
  const groups = useMemo(() => endpointsByTag(reference), [reference]);

  const needle = query.trim().toLowerCase();
  const visible = groups
    .map(
      ([tag, endpoints]) =>
        [
          tag,
          needle
            ? endpoints.filter((e) =>
                `${e.method} ${e.path} ${e.operation.summary ?? ""} ${tag}`
                  .toLowerCase()
                  .includes(needle),
              )
            : endpoints,
        ] as const,
    )
    .filter(([, endpoints]) => endpoints.length > 0);

  return (
    <div className="space-y-4">
      <Input
        type="search"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder={t("api_reference.search_placeholder")}
        aria-label={t("api_reference.search_label")}
      />
      {visible.length === 0 && (
        <p className="text-sm text-muted-foreground">
          {t("api_reference.no_match")}
        </p>
      )}
      {visible.map(([tag, endpoints]) => (
        <section key={tag} className="space-y-2">
          <h3 className="text-sm font-semibold text-foreground">
            {tag || t("api_reference.other")}
          </h3>
          <div className="divide-y rounded-lg border">
            {endpoints.map((e) => (
              <Endpoint
                key={`${e.method} ${e.path}`}
                endpoint={e}
                reference={reference}
              />
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}

function Endpoint({
  endpoint,
  reference,
}: {
  endpoint: ApiEndpoint;
  reference: ApiReference;
}) {
  const { t } = useT();
  const { method, path, operation } = endpoint;
  // The body renders on first open only: drawn up front, three hundred
  // schema trees made the page slow to load and slower to search.
  const [opened, setOpened] = useState(false);
  const request = firstSchema(operation.requestBody?.content);
  const responses = Object.entries(operation.responses ?? {});

  return (
    <details
      className="group"
      onToggle={(e) => {
        if ((e.currentTarget as HTMLDetailsElement).open) setOpened(true);
      }}
    >
      <summary className="flex cursor-pointer flex-wrap items-center gap-2 px-3 py-2 hover:bg-muted/50">
        <Badge
          variant={
            METHOD_VARIANT[method as keyof typeof METHOD_VARIANT] ?? "neutral"
          }
          className="w-16 justify-center font-mono text-[10px]"
        >
          {method}
        </Badge>
        <code className="break-all font-mono text-xs text-foreground">
          {path}
        </code>
        <span className="text-xs text-muted-foreground">
          {operation.summary}
        </span>
        <span className="ml-auto flex flex-wrap gap-1">
          {operation["x-abilities"].map((a) => (
            <Badge key={a} variant="outline" className="text-[10px]">
              {a}
            </Badge>
          ))}
        </span>
      </summary>

      {opened && (
        <div className="space-y-3 px-3 pb-3 pt-1 text-sm">
          <p className="text-xs text-muted-foreground">
            {t(
              "api_reference.needs_one_of",
              "Needs one of these abilities: :abilities",
              { abilities: operation["x-abilities"].join(", ") },
            )}
          </p>
          {operation.description && (
            <p className="whitespace-pre-line text-xs text-muted-foreground">
              {operation.description}
            </p>
          )}

          {operation.parameters && operation.parameters.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold">
                {t("api_reference.parameters")}
              </h4>
              <ul className="space-y-1 font-mono text-xs">
                {operation.parameters.map((p) => (
                  <li key={`${p.in}:${p.name}`}>
                    <span className="font-semibold">{p.name}</span>
                    {p.required && <span className="text-destructive">*</span>}
                    <span className="text-muted-foreground">
                      {" "}
                      ({p.in}
                      {p.schema ? `, ${typeLabel(reference, p.schema)}` : ""})
                    </span>
                    {p.description && (
                      <span className="ml-2 font-sans text-muted-foreground">
                        · {p.description}
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {request && (
            <div>
              <h4 className="mb-1 text-xs font-semibold">
                {t("api_reference.request_body")}
              </h4>
              <SchemaView reference={reference} schema={request} />
            </div>
          )}

          {responses.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold">
                {t("api_reference.responses")}
              </h4>
              <ul className="space-y-2">
                {responses.map(([status, response]) => {
                  const shared = response.$ref
                    ? reference.components?.responses?.[
                        response.$ref.split("/").pop() ?? ""
                      ]
                    : undefined;
                  const body = shared ?? response;
                  const schema = resolveRef(
                    reference,
                    firstSchema(body.content),
                  );
                  return (
                    <li key={status} className="space-y-1">
                      <span className="font-mono text-xs font-semibold">
                        {status}
                      </span>
                      {body.description && (
                        <span className="ml-2 text-xs text-muted-foreground">
                          {body.description.replace(/`/g, "")}
                        </span>
                      )}
                      {schema && status.startsWith("2") && (
                        <SchemaView
                          reference={reference}
                          schema={firstSchema(body.content)}
                        />
                      )}
                    </li>
                  );
                })}
              </ul>
            </div>
          )}
        </div>
      )}
    </details>
  );
}
