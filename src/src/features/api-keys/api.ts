import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

/**
 * `abilities` is the list `StoreApiKeyRequest` validated (plus `scim` for
 * tokens issued from the SCIM settings page, which are API keys too).
 */
export type ApiKey =
  operations["apiKey.index"]["responses"][200]["content"]["application/json"]["keys"][number];
export type CreatedApiKey =
  operations["apiKey.store"]["responses"][201]["content"]["application/json"];
export type ApiKeyPayload = components["schemas"]["StoreApiKeyRequest"];
export type ApiKeyAbility = ApiKeyPayload["abilities"][number];

const keys = { all: ["api-keys"] as const };

/** Unrevoked keys, newest first. `GET /api-keys` is not paginated. */
export function useApiKeys() {
  return useQuery<ApiKey[]>({
    queryKey: keys.all,
    queryFn: async () => (await apiClient.get("/api-keys")).data.keys,
  });
}

/**
 * The response carries the plain key (`key`) exactly once — the server stores
 * only its hash, and the list never returns it. The page shows it from this
 * result; nothing re-fetches it.
 */
export function useCreateApiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: ApiKeyPayload): Promise<CreatedApiKey> =>
      (await apiClient.post("/api-keys", payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
    },
    // A mutation keeps its last result in the cache; the plain key has no
    // business outliving the banner that displayed it.
    gcTime: 0,
  });
}

/** Revocation is permanent: the key stops authenticating immediately. */
export function useRevokeApiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string): Promise<void> => {
      await apiClient.delete(`/api-keys/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
    },
  });
}
