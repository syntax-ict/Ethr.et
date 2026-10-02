import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

type Schemas = components["schemas"];

// Shapes come from the generated contract. GET /profile assembles nested
// resources with `->resolve()`, which Scramble cannot follow, so those lists
// are restated from the resources they resolve — each override says why.

/**
 * A change to an approval-gated field, staged until HR reviews it — name,
 * name_am, TIN, date of birth and bank details.
 *
 * The four names are `?->` reads (and `displayName()` is `?string`), so each
 * can be null; Scramble publishes them as plain strings. `status` is the
 * ProfileUpdateStatus enum's value.
 */
export type ProfileUpdateRequest = Omit<
  Schemas["ProfileUpdateRequestResource"],
  | "status"
  | "employee_public_id"
  | "employee_name"
  | "requested_by_name"
  | "reviewed_by_name"
> & {
  status: "pending" | "approved" | "rejected" | "withdrawn";
  employee_public_id: string | null;
  employee_name: string | null;
  requested_by_name: string | null;
  reviewed_by_name: string | null;
};

export type EmergencyContact = Schemas["EmergencyContactResource"];

type ProfileContract =
  operations["profile.show"]["responses"][200]["content"]["application/json"];

/** `account_number_masked` is the tail four characters only. */
export type ProfileBankDetail = ProfileContract["bank_details"][number];

/**
 * `present()` returns stored strings; only values UpdateProfilePreferencesRequest
 * admitted are ever stored, so the calendar is that request's enum.
 */
export type ProfilePreferences = Omit<
  ProfileContract["preferences"],
  "calendar"
> & {
  calendar: NonNullable<Schemas["UpdateProfilePreferencesRequest"]["calendar"]>;
};

/**
 * Overrides: `emergency_contacts`, `pending_updates` and `recent_updates` are
 * resource collections `->resolve()`d inline (Scramble: `unknown[]`), and
 * `editable_fields` lists model constants it types as a tuple and `unknown[]`.
 * `recent_updates` is the last ten decided requests.
 */
export type ProfileResponse = Omit<
  ProfileContract,
  | "preferences"
  | "emergency_contacts"
  | "pending_updates"
  | "recent_updates"
  | "editable_fields"
> & {
  preferences: ProfilePreferences;
  emergency_contacts: EmergencyContact[];
  pending_updates: ProfileUpdateRequest[];
  recent_updates: ProfileUpdateRequest[];
  editable_fields: { self: string[]; gated: string[] };
};

const PROFILE_KEY = ["profile", "me"] as const;

export function useMyProfile() {
  return useQuery<ProfileResponse>({
    queryKey: PROFILE_KEY,
    queryFn: async () => {
      const { data } = await apiClient.get("/profile");
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

/** Gated fields in this body are staged for HR review, not applied. */
export type UpdateProfilePayload = Schemas["UpdateProfileRequest"];

type UpdateProfileContract =
  operations["profile.update"]["responses"][200]["content"]["application/json"];

/**
 * `pending_approval` is null when nothing was staged, which Scramble misses;
 * its `fields` is a `pluck()->all()` list and `requests` a resolved collection.
 */
export type UpdateProfileResult = Omit<
  UpdateProfileContract,
  "pending_approval"
> & {
  pending_approval:
    | (Omit<
        UpdateProfileContract["pending_approval"],
        "fields" | "requests"
      > & { fields: string[]; requests: ProfileUpdateRequest[] })
    | null;
};

/** Everything the profile screens touch is derived from GET /profile. */
function useProfileInvalidation() {
  const queryClient = useQueryClient();

  return () => {
    queryClient.invalidateQueries({ queryKey: ["profile"] });
    // A staged change also lands in the HR review queue (/approvals).
    queryClient.invalidateQueries({ queryKey: ["approvals"] });
  };
}

export function useUpdateProfile() {
  const invalidate = useProfileInvalidation();

  return useMutation({
    mutationFn: async (payload: UpdateProfilePayload) => {
      const { data } = await apiClient.put<UpdateProfileResult>(
        "/profile",
        payload,
      );
      return data;
    },
    onSuccess: invalidate,
  });
}

export type ProfilePhotoResult =
  operations["profilePhoto.store"]["responses"][201]["content"]["application/json"];

/**
 * The photo goes to its own POST endpoint: a multipart body cannot ride on a
 * PUT, and the Content-Type override matters — with the client's default
 * `application/json` axios serialises the FormData to JSON and the file is lost.
 */
export function useUploadProfilePhoto() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData();
      form.append("photo", file);

      const { data } = await apiClient.post<ProfilePhotoResult>(
        "/profile/photo",
        form,
        { headers: { "Content-Type": "multipart/form-data" } },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["profile"] });
      // The header avatar reads from /auth/me.
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
    },
  });
}

export function useRemoveProfilePhoto() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await apiClient.delete("/profile/photo");
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["profile"] });
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
    },
  });
}

export type ProfilePreferencesPayload =
  Schemas["UpdateProfilePreferencesRequest"];

export function useUpdatePreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: ProfilePreferencesPayload) => {
      const { data } = await apiClient.put<ProfilePreferences>(
        "/profile/preferences",
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["profile"] });
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
    },
  });
}

/** Store and update both validate with StoreProfileEmergencyContactRequest. */
export type EmergencyContactPayload =
  Schemas["StoreProfileEmergencyContactRequest"];

export function useCreateEmergencyContact() {
  const invalidate = useProfileInvalidation();

  return useMutation({
    mutationFn: async (payload: EmergencyContactPayload) => {
      const { data } = await apiClient.post<EmergencyContact>(
        "/profile/emergency-contacts",
        payload,
      );
      return data;
    },
    onSuccess: invalidate,
  });
}

export function useUpdateEmergencyContact() {
  const invalidate = useProfileInvalidation();

  return useMutation({
    mutationFn: async (vars: {
      publicId: string;
      payload: EmergencyContactPayload;
    }) => {
      const { data } = await apiClient.put<EmergencyContact>(
        `/profile/emergency-contacts/${vars.publicId}`,
        vars.payload,
      );
      return data;
    },
    onSuccess: invalidate,
  });
}

export function useDeleteEmergencyContact() {
  const invalidate = useProfileInvalidation();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/profile/emergency-contacts/${publicId}`);
    },
    onSuccess: invalidate,
  });
}

/** Retract a staged change nobody has reviewed yet. */
export function useWithdrawProfileUpdate() {
  const invalidate = useProfileInvalidation();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.delete<ProfileUpdateRequest>(
        `/profile-update-requests/${publicId}`,
      );
      return data;
    },
    onSuccess: invalidate,
  });
}
