import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";
import type { Employee, EmployeeFormData } from "./types";

export function useEmployees(params?: {
  page?: number;
  search?: string;
  per_page?: number;
  sort?: string;
}) {
  return useQuery<PaginatedResponse<Employee>>({
    queryKey: ["employees", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/employees", { params });
      return data;
    },
    staleTime: 2 * 60 * 1000,
  });
}

export function useEmployee(publicId: string) {
  return useQuery<Employee>({
    queryKey: ["employees", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/employees/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateEmployee() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (formData: EmployeeFormData) => {
      const { data } = await apiClient.post("/employees", formData);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

export function useUpdateEmployee(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (formData: Partial<EmployeeFormData>) => {
      const { data } = await apiClient.put(`/employees/${publicId}`, formData);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

export function useDeleteEmployee() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/employees/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

export function useEmployeeStats() {
  return useQuery({
    queryKey: ["employees", "stats"],
    queryFn: async () => {
      const { data } = await apiClient.get("/employees/stats");
      return data;
    },
  });
}
