import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

type Schemas = components["schemas"];

// Shapes come from the generated contract; the one override says why.

/**
 * A change to an approval-gated field, staged until HR reviews it — name,
 * name_am, TIN, date of birth and bank details.
 */
export type ProfileUpdateRequest = Schemas["ProfileUpdateRequestResource"];

export type EmergencyContact = Schemas["EmergencyContactResource"];

type ProfileContract =
  operations["profile.show"]["responses"][200]["content"]["application/json"];

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

/** `recent_updates` is the last ten decided requests. */
export type ProfileResponse = Omit<ProfileContract, "preferences"> & {
  preferences: ProfilePreferences;
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

/** `pending_approval` is null when nothing was staged for review. */
export type UpdateProfileResult =
  operations["profile.update"]["responses"][200]["content"]["application/json"];

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
