"use client";

import { useT } from "@/lib/i18n/useT";
import { resolveRef, type ApiReference, type JsonSchema } from "./api";

/**
 * A request or response schema as a compact tree: each field with its type,
 * whether it is required, and its description. `allOf` is merged, a
 * `null` alternative reads as "nullable", and a schema that refers back to
 * one being drawn stops there rather than recursing.
 */
export function SchemaView({
  reference,
  schema,
}: {
  reference: ApiReference;
  schema: JsonSchema | undefined;
}) {
  if (!schema) return null;
  return (
    <div className="overflow-x-auto rounded-md border bg-muted/30 p-3 font-mono text-xs">
      <Node reference={reference} schema={schema} depth={0} seen={[]} />
    </div>
  );
}

const MAX_DEPTH = 4;

function refName(schema: JsonSchema): string | null {
  return schema.$ref ? (schema.$ref.split("/").pop() ?? null) : null;
}

/** allOf merged into one object; refs resolved. */
function flatten(reference: ApiReference, schema: JsonSchema): JsonSchema {
  const resolved = resolveRef(reference, schema) ?? schema;
  if (!resolved.allOf) return resolved;
  const merged: JsonSchema = { type: "object", properties: {}, required: [] };
  for (const part of resolved.allOf) {
    const p = flatten(reference, part);
    Object.assign(merged.properties!, p.properties ?? {});
    merged.required!.push(...(p.required ?? []));
  }
  return merged;
}

/** "string", "integer | null", "EmployeeResource[]", "object". */
export function typeLabel(reference: ApiReference, schema: JsonSchema): string {
  const name = refName(schema);
  if (name) return name;
  const alternatives = schema.anyOf ?? schema.oneOf;
  if (alternatives) {
    return alternatives.map((s) => typeLabel(reference, s)).join(" | ");
  }
  if (schema.allOf) return "object";
  if (schema.enum) return schema.enum.map((v) => JSON.stringify(v)).join(" | ");
  if (schema.const !== undefined) return JSON.stringify(schema.const);
  const type = Array.isArray(schema.type)
    ? schema.type.join(" | ")
    : schema.type;
  if (type === "array" && schema.items) {
    const item = typeLabel(reference, schema.items);
    return item.includes(" | ") ? `(${item})[]` : `${item}[]`;
  }
  const label = type ?? "any";
  return schema.format ? `${label} (${schema.format})` : label;
}

/** The object-shaped schema worth expanding below a field, if any. */
function expandable(
  reference: ApiReference,
  schema: JsonSchema,
): JsonSchema | null {
  const alternatives = schema.anyOf ?? schema.oneOf;
  if (alternatives) {
    const real = alternatives.filter((s) => s.type !== "null");
    return real.length === 1 ? expandable(reference, real[0]) : null;
  }
  if (schema.type === "array" && schema.items) {
    return expandable(reference, schema.items);
  }
  const flat = flatten(reference, schema);
  return flat.properties && Object.keys(flat.properties).length > 0
    ? schema
    : null;
}

function Node({
  reference,
  schema,
  depth,
  seen,
}: {
  reference: ApiReference;
  schema: JsonSchema;
  depth: number;
  seen: string[];
}) {
  const { t } = useT();
  const name = refName(schema);
  if (name && seen.includes(name)) {
    return <span className="text-muted-foreground">{name} …</span>;
  }
  const nextSeen = name ? [...seen, name] : seen;
  const flat = flatten(reference, schema);
  const properties = Object.entries(flat.properties ?? {});

  if (properties.length === 0) {
    return <span>{typeLabel(reference, schema)}</span>;
  }
  if (depth >= MAX_DEPTH) {
    return <span className="text-muted-foreground">{name ?? "object"} …</span>;
  }

  const required = new Set(flat.required ?? []);
  return (
    <ul className="space-y-1">
      {properties.map(([field, fieldSchema]) => {
        const child = expandable(reference, fieldSchema);
        return (
          <li key={field} className="pl-3">
            <span className="font-semibold text-foreground">{field}</span>
            {required.has(field) && (
              <span
                className="text-destructive"
                aria-label={t("api_reference.required")}
              >
                *
              </span>
            )}
            <span className="text-muted-foreground">
              {": "}
              {typeLabel(reference, fieldSchema)}
            </span>
            {fieldSchema.description && (
              <span className="ml-2 font-sans text-muted-foreground">
                · {fieldSchema.description}
              </span>
            )}
            {child && (
              <div className="mt-1 border-l pl-2">
                <Node
                  reference={reference}
                  schema={
                    child.type === "array" && child.items ? child.items : child
                  }
                  depth={depth + 1}
                  seen={nextSeen}
                />
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}
