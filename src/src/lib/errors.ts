import { isAxiosError } from "axios";
import { toast } from "sonner";
import type { ApiError } from "@/api/client";

export function toastError(error: unknown, fallback: string): void {
  if (isAxiosError<ApiError>(error) && error.response?.data?.detail) {
    toast.error(error.response.data.detail);
    return;
  }
  toast.error(fallback);
}
