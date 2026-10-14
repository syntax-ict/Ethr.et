import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

/**
 * The reference an organisation's developers read: the operations an API key
 * can call. Built on the server from the OpenAPI document
 * (`App\Support\ApiReference`); only the parts this page renders are typed.
 */
export interface JsonSchema {
  $ref?: string;
  type?: string | string[];
  format?: string;
  description?: string;
  enum?: unknown[];
  const?: unknown;
  properties?: Record<string, JsonSchema>;
  required?: string[];
  items?: JsonSchema;
  allOf?: JsonSchema[];
  anyOf?: JsonSchema[];
  oneOf?: JsonSchema[];
  additionalProperties?: boolean | JsonSchema;
}

export interface ApiParameter {
  name: string;
  in: string;
  required?: boolean;
  description?: string;
  schema?: JsonSchema;
}

interface MediaContent {
  content?: Record<string, { schema?: JsonSchema }>;
}

export interface ApiOperation {
  operationId?: string;
  summary?: string;
  description?: string;
  tags?: string[];
  "x-abilities": string[];
  parameters?: ApiParameter[];
  requestBody?: MediaContent & { $ref?: string };
  responses?: Record<
    string,
    MediaContent & { description?: string; $ref?: string }
  >;
}

export interface ApiReference {
  servers?: { url: string }[];
  paths: Record<string, Record<string, ApiOperation>>;
  components?: {
    schemas?: Record<string, JsonSchema>;
    responses?: Record<string, MediaContent & { description?: string }>;
  };
  "x-webhook-events": string[];
}

/** One operation, flattened for listing and searching. */
export interface ApiEndpoint {
  method: string;
  path: string;
  tag: string;
  operation: ApiOperation;
}

export function useApiReference() {
  return useQuery<ApiReference>({
    queryKey: ["api-keys", "reference"],
    queryFn: async () => (await apiClient.get("/api-keys/reference")).data,
    staleTime: 5 * 60_000,
  });
}

/** Every operation, grouped by its first tag, in path order. */
export function endpointsByTag(
  reference: ApiReference,
): [string, ApiEndpoint[]][] {
  const groups = new Map<string, ApiEndpoint[]>();
  for (const [path, operations] of Object.entries(reference.paths)) {
    for (const [method, operation] of Object.entries(operations)) {
      const tag = operation.tags?.[0] ?? "";
      const list = groups.get(tag) ?? [];
      list.push({ method: method.toUpperCase(), path, tag, operation });
      groups.set(tag, list);
    }
  }
  return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
}

/** The schema a `$ref` names, or the schema itself. */
export function resolveRef(
  reference: ApiReference,
  schema: JsonSchema | undefined,
): JsonSchema | undefined {
  if (!schema?.$ref) return schema;
  const name = schema.$ref.replace("#/components/schemas/", "");
  return reference.components?.schemas?.[name];
}
