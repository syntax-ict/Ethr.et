import { z } from "zod";
import { rules } from "@/lib/forms/rules";

/**
 * What the sign-up form accepts, in one place the form and its tests share.
 *
 * It lived inside the form component, and `auth-validation.test.ts` kept its
 * own copy: that copy still called the phone optional and had no
 * `organization_kind` long after the form required both, so it passed while
 * testing nothing the form did.
 */

/**
 * The phone without the spaces, dashes and brackets people write numbers
 * with. The server drops every non-digit before it validates
 * (`EthiopianPhone::canonical`), so "0912 345 678" is accepted there and has
 * to be accepted here.
 */
const adminPhone = z
  .string()
  .transform((value) => value.replace(/[\s\-().]/g, ""))
  .pipe(rules.phone());

/** Whether the server would take this as the administrator's phone. */
export function isAdminPhone(value: string): boolean {
  return adminPhone.safeParse(value).success;
}

export const registerSchema = z
  .object({
    organization_name: z.string().min(2, "auth.complete_org_step"),
    organization_type: z.string().min(1, "auth.org_type_required"),
    organization_kind: z.string().min(1, "auth.org_kind_required"),
    subdomain: z.string().min(3, "auth.complete_org_step").max(63),
    admin_name: z.string().min(2, "auth.complete_admin_step"),
    admin_email: z.string().email("auth.complete_admin_step"),
    admin_phone: adminPhone,
    password: z.string().min(8, "auth.password_requirements"),
    password_confirmation: z.string().min(8, "auth.password_requirements"),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "auth.passwords_no_match",
    path: ["password_confirmation"],
  });

export type RegisterFormValues = z.infer<typeof registerSchema>;
