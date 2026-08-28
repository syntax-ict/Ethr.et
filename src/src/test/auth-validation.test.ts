import { describe, it, expect } from "vitest";
import { z } from "zod";

const loginSchema = z.object({
  tenant: z.string().min(1, "auth.enter_subdomain"),
  email: z.string().email("auth.invalid_credentials"),
  password: z.string().min(1, "auth.invalid_credentials"),
});

const registerSchema = z
  .object({
    organization_name: z.string().min(2, "auth.complete_org_step"),
    organization_type: z.string().min(1),
    subdomain: z.string().min(3, "auth.complete_org_step").max(63),
    admin_name: z.string().min(2, "auth.complete_admin_step"),
    admin_email: z.string().email("auth.complete_admin_step"),
    admin_phone: z.string().max(20).optional().or(z.literal("")),
    password: z.string().min(8, "auth.password_requirements"),
    password_confirmation: z.string().min(8, "auth.password_requirements"),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "auth.passwords_no_match",
    path: ["password_confirmation"],
  });

describe("Login schema validation", () => {
  it("accepts valid login data", () => {
    const result = loginSchema.safeParse({
      tenant: "acme",
      email: "admin@acme.et",
      password: "password123",
    });
    expect(result.success).toBe(true);
  });

  it("rejects empty tenant", () => {
    const result = loginSchema.safeParse({
      tenant: "",
      email: "admin@acme.et",
      password: "password123",
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toContain("tenant");
    }
  });

  it("rejects invalid email format", () => {
    const result = loginSchema.safeParse({
      tenant: "acme",
      email: "not-an-email",
      password: "password123",
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toContain("email");
    }
  });

  it("rejects empty password", () => {
    const result = loginSchema.safeParse({
      tenant: "acme",
      email: "admin@acme.et",
      password: "",
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toContain("password");
    }
  });

  it("accepts Amharic characters in tenant as subdomain would be Latin", () => {
    const result = loginSchema.safeParse({
      tenant: "test-org",
      email: "test@example.com",
      password: "abc123",
    });
    expect(result.success).toBe(true);
  });
});

describe("Register schema validation", () => {
  const validData = {
    organization_name: "Addis Tech Solutions",
    organization_type: "general",
    subdomain: "addis-tech",
    admin_name: "Abebe Kebede",
    admin_email: "abebe@addistech.et",
    admin_phone: "+251911223344",
    password: "StrongPass123!",
    password_confirmation: "StrongPass123!",
  };

  it("accepts valid registration data", () => {
    const result = registerSchema.safeParse(validData);
    expect(result.success).toBe(true);
  });

  it("accepts empty phone (optional)", () => {
    const result = registerSchema.safeParse({
      ...validData,
      admin_phone: "",
    });
    expect(result.success).toBe(true);
  });

  it("rejects org name under 2 characters", () => {
    const result = registerSchema.safeParse({
      ...validData,
      organization_name: "A",
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toContain("organization_name");
    }
  });

  it("rejects subdomain under 3 characters", () => {
    const result = registerSchema.safeParse({
      ...validData,
      subdomain: "ab",
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toContain("subdomain");
    }
  });

  it("rejects subdomain over 63 characters", () => {
    const result = registerSchema.safeParse({
      ...validData,
      subdomain: "a".repeat(64),
    });
    expect(result.success).toBe(false);
  });

  it("rejects invalid admin email", () => {
    const result = registerSchema.safeParse({
      ...validData,
      admin_email: "not-valid",
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toContain("admin_email");
    }
  });

  it("rejects admin name under 2 characters", () => {
    const result = registerSchema.safeParse({
      ...validData,
      admin_name: "A",
    });
    expect(result.success).toBe(false);
  });

  it("rejects password under 8 characters", () => {
    const result = registerSchema.safeParse({
      ...validData,
      password: "Short1!",
      password_confirmation: "Short1!",
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toContain("password");
    }
  });

  it("rejects mismatched passwords", () => {
    const result = registerSchema.safeParse({
      ...validData,
      password: "StrongPass123!",
      password_confirmation: "DifferentPass456!",
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      const confirmIssue = result.error.issues.find((i) =>
        i.path.includes("password_confirmation"),
      );
      expect(confirmIssue).toBeDefined();
    }
  });

  it("accepts Ethiopian phone number format", () => {
    const result = registerSchema.safeParse({
      ...validData,
      admin_phone: "+251911223344",
    });
    expect(result.success).toBe(true);
  });

  it("rejects phone number over 20 characters", () => {
    const result = registerSchema.safeParse({
      ...validData,
      admin_phone: "+".padEnd(22, "1"),
    });
    expect(result.success).toBe(false);
  });
});
