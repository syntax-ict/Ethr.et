import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

export interface ProfileResponse {
  user: {
    public_id: string;
    email: string;
    phone: string | null;
    locale: string;
  };
  employee: {
    public_id: string;
    name: string;
    name_am: string | null;
    phone: string | null;
    gender: string | null;
    date_of_birth: string | null;
    nationality: string | null;
    marital_status: string | null;
    hire_date: string | null;
    photo_path: string | null;
    department: string | null;
    position: string | null;
    branch: string | null;
    grade: string | null;
  } | null;
}

export function useMyProfile() {
  return useQuery<ProfileResponse>({
    queryKey: ["profile", "me"],
    queryFn: async () => {
      const { data } = await apiClient.get("/profile");
      return data;
    },
  });
}

export interface UpdateProfilePayload {
  phone?: string;
  address?: string;
  emergency_contact_name?: string;
  emergency_contact_phone?: string;
  emergency_contact_relationship?: string;
  name?: string;
  bank_account_number?: string;
  bank_name?: string;
}

export function useUpdateProfile() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: UpdateProfilePayload) => {
      const { data } = await apiClient.put("/profile", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["profile"] });
    },
  });
}
