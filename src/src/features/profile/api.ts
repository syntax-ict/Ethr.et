import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

/**
 * A change to an approval-gated field, staged until HR reviews it.
 *
 * These are the fields an employee may propose but not apply — name, name_am,
 * TIN, date of birth and bank details.
 */
export interface ProfileUpdateRequest {
  public_id: string;
  field_name: string;
  old_value: string | null;
  new_value: string | null;
  status: "pending" | "approved" | "rejected" | "withdrawn";
  employee_public_id: string | null;
  employee_name: string | null;
  requested_by_name: string | null;
  reviewed_by_name: string | null;
  reviewed_at: string | null;
  review_notes: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface EmergencyContact {
  public_id: string;
  name: string;
  relationship: string;
  phone: string;
  email: string | null;
  priority: number | null;
}

export interface ProfileBankDetail {
  public_id: string;
  bank_name: string;
  branch_name: string | null;
  /** Tail four characters only — the full number is never sent to the browser. */
  account_number_masked: string | null;
  is_primary: boolean;
}

export interface ProfilePreferences {
  locale: string;
  theme: string;
  calendar: "gregorian" | "ethiopian" | "dual";
}

export interface ProfileResponse {
  user: {
    public_id: string;
    email: string;
    phone: string | null;
    locale: string;
    role: string | null;
    status: string | null;
    mfa_enabled: boolean;
    email_verified_at: string | null;
    last_login_at: string | null;
  };
  employee: {
    public_id: string;
    name: string;
    name_am: string | null;
    employee_code: string | null;
    phone: string | null;
    gender: string | null;
    date_of_birth: string | null;
    nationality: string | null;
    marital_status: string | null;
    hire_date: string | null;
    status: string | null;
    tin_masked: string | null;
    photo_path: string | null;
    photo_url: string | null;
    photo_thumb_url: string | null;
    department: string | null;
    position: string | null;
    branch: string | null;
    grade: string | null;
    supervisor: string | null;
  } | null;
  preferences: ProfilePreferences;
  emergency_contacts: EmergencyContact[];
  bank_details: ProfileBankDetail[];
  pending_updates: ProfileUpdateRequest[];
  /** Last ten decided requests — approved, rejected or withdrawn. */
  recent_updates: ProfileUpdateRequest[];
  /** Which fields apply immediately and which are staged for HR review. */
  editable_fields: { self: string[]; gated: string[] };
}

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

export interface UpdateProfilePayload {
  phone?: string;
  marital_status?: string;
  nationality?: string;
  emergency_contact_name?: string;
  emergency_contact_phone?: string;
  emergency_contact_relationship?: string;
  // Gated — these are staged for HR review, not applied.
  name?: string;
  name_am?: string;
  tin?: string;
  date_of_birth?: string;
  bank_account_number?: string;
  bank_name?: string;
}

export interface UpdateProfileResult {
  message: string;
  pending_approval: {
    status: string;
    fields: string[];
    requests: ProfileUpdateRequest[];
    message: string;
  } | null;
  was_duplicate: boolean;
}

/** Everything the profile screens touch is derived from GET /profile. */
function useProfileInvalidation() {
  const queryClient = useQueryClient();

  return () => {
    queryClient.invalidateQueries({ queryKey: ["profile"] });
    // A staged change also lands in the HR review queue.
    queryClient.invalidateQueries({ queryKey: ["profile-update-requests"] });
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

export interface ProfilePhotoResult {
  photo_path: string;
  photo_url: string | null;
  photo_thumb_url: string | null;
}

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

export function useUpdatePreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Partial<ProfilePreferences>) => {
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

export interface EmergencyContactPayload {
  name: string;
  relationship: string;
  phone: string;
  email?: string | null;
  priority?: number;
}

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

/** HR review queue. Requires `employee.update`. */
export function useProfileUpdateRequests(status = "pending") {
  return useQuery<{ data: ProfileUpdateRequest[] }>({
    queryKey: ["profile-update-requests", status],
    queryFn: async () => {
      const { data } = await apiClient.get("/profile-update-requests", {
        params: { status },
      });
      return data;
    },
  });
}

export function useReviewProfileUpdate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (vars: {
      publicId: string;
      action: "approve" | "reject";
      notes?: string;
    }) => {
      const { data } = await apiClient.post<ProfileUpdateRequest>(
        `/profile-update-requests/${vars.publicId}/review`,
        { action: vars.action, notes: vars.notes },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["profile-update-requests"] });
      queryClient.invalidateQueries({ queryKey: ["approvals"] });
      queryClient.invalidateQueries({ queryKey: ["employees"] });
      queryClient.invalidateQueries({ queryKey: ["profile"] });
    },
  });
}
