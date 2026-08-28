"use client";

import { useState, useCallback } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  Eye,
  EyeOff,
  Check,
  X,
  Loader2,
  ArrowLeft,
  ArrowRight,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";

const orgTypeKeys = [
  "government",
  "bank",
  "hospital",
  "manufacturing",
  "ngo",
  "hotel",
  "university",
  "general",
] as const;

const steps = ["organization", "admin", "security"] as const;
type Step = (typeof steps)[number];

function slugify(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 63);
}

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
type RegisterForm = z.infer<typeof registerSchema>;

function getPasswordStrength(
  password: string,
  t: (key: string, fallback?: string) => string,
): { score: number; label: string; color: string } {
  let score = 0;
  if (password.length >= 8) score++;
  if (password.length >= 12) score++;
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
  if (/\d/.test(password)) score++;
  if (/[^a-zA-Z0-9]/.test(password)) score++;

  if (score <= 1)
    return {
      score,
      label: t("auth.password_weak", "Weak"),
      color: "bg-destructive",
    };
  if (score <= 2)
    return {
      score,
      label: t("auth.password_fair", "Fair"),
      color: "bg-status-warning",
    };
  if (score <= 3)
    return {
      score,
      label: t("auth.password_good", "Good"),
      color: "bg-brand-accent",
    };
  return {
    score,
    label: t("auth.password_strong", "Strong"),
    color: "bg-status-success",
  };
}

export function RegisterForm() {
  const router = useRouter();
  const { t } = useT();
  const [stepIndex, setStepIndex] = useState(() => {
    if (typeof window !== "undefined") {
      localStorage.removeItem("tenant");
    }
    return 0;
  });
  const currentStep: Step = steps[stepIndex];

  const [showPassword, setShowPassword] = useState(false);
  const [serverError, setServerError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [subdomainAvailable, setSubdomainAvailable] = useState<boolean | null>(
    null,
  );
  const [checkingSubdomain, setCheckingSubdomain] = useState(false);

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    trigger,
    formState: { errors, isSubmitting },
  } = useForm<RegisterForm>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      organization_name: "",
      organization_type: "general",
      subdomain: "",
      admin_name: "",
      admin_email: "",
      admin_phone: "",
      password: "",
      password_confirmation: "",
    },
    mode: "onBlur",
  });

  /**
   * A field is invalid if either source says so: the client-side zod resolver
   * (`errors`) or the server's RFC-7807 `errors` map (`fieldErrors`). Both
   * already drive the visible message; these flags exist so the same condition
   * also drives `aria-invalid` and `aria-describedby` on the control, which
   * were missing entirely — the messages were on screen but invisible to
   * assistive tech and unlinked to any input.
   */
  const hasError_organization_name = Boolean(
    errors.organization_name || fieldErrors.organization_name,
  );
  const hasError_subdomain = Boolean(errors.subdomain || fieldErrors.subdomain);
  const hasError_admin_name = Boolean(
    errors.admin_name || fieldErrors.admin_name,
  );
  const hasError_admin_email = Boolean(
    errors.admin_email || fieldErrors.admin_email,
  );
  const hasError_password = Boolean(errors.password || fieldErrors.password);
  const hasError_password_confirmation = Boolean(errors.password_confirmation);

  const password = watch("password");
  const passwordConfirmation = watch("password_confirmation");
  const subdomain = watch("subdomain");
  const orgName = watch("organization_name");
  const adminName = watch("admin_name");
  const adminEmail = watch("admin_email");

  const passwordStrength = getPasswordStrength(password, t);

  const checkSubdomain = useCallback(async (value: string) => {
    if (value.length < 3) {
      setSubdomainAvailable(null);
      return;
    }
    setCheckingSubdomain(true);
    try {
      const response = await apiClient.get("/register/check-subdomain", {
        params: { subdomain: value },
      });
      setSubdomainAvailable(response.data.available);
    } catch {
      setSubdomainAvailable(null);
    } finally {
      setCheckingSubdomain(false);
    }
  }, []);

  function handleOrgNameChange(value: string) {
    setValue("organization_name", value);
    const suggested = slugify(value);
    setValue("subdomain", suggested);
    if (suggested.length >= 3) {
      checkSubdomain(suggested);
    }
  }

  function handleSubdomainChange(value: string) {
    const cleaned = value.toLowerCase().replace(/[^a-z0-9-]/g, "");
    setValue("subdomain", cleaned);
    setSubdomainAvailable(null);
    if (cleaned.length >= 3) {
      const timeout = setTimeout(() => checkSubdomain(cleaned), 500);
      return () => clearTimeout(timeout);
    }
  }

  const step1Valid =
    orgName.trim().length >= 2 &&
    subdomain.trim().length >= 3 &&
    subdomainAvailable !== false;
  const step2Valid =
    adminName.trim().length >= 2 && /\S+@\S+\.\S+/.test(adminEmail);
  const step3Valid = password.length >= 8 && password === passwordConfirmation;

  async function goNext() {
    setServerError("");
    if (currentStep === "organization") {
      const valid = await trigger([
        "organization_name",
        "subdomain",
        "organization_type",
      ]);
      if (!valid || !step1Valid) {
        setServerError(
          t(
            "auth.complete_org_step",
            "Please complete the organization details.",
          ),
        );
        return;
      }
    }
    if (currentStep === "admin") {
      const valid = await trigger(["admin_name", "admin_email"]);
      if (!valid || !step2Valid) {
        setServerError(
          t(
            "auth.complete_admin_step",
            "Please complete the admin account details.",
          ),
        );
        return;
      }
    }
    setStepIndex((i) => Math.min(i + 1, steps.length - 1));
  }

  function goBack() {
    setServerError("");
    setStepIndex((i) => Math.max(i - 1, 0));
  }

  async function onSubmit(data: RegisterForm) {
    setServerError("");
    setFieldErrors({});

    try {
      await apiClient.get("/sanctum/csrf-cookie", { baseURL: "" });
    } catch {
      setServerError(
        t(
          "auth.server_error",
          "Unable to connect to the server. Please try again later.",
        ),
      );
      return;
    }

    try {
      await apiClient.post("/auth/register", {
        organization_name: data.organization_name,
        organization_type: data.organization_type,
        subdomain: data.subdomain,
        admin_name: data.admin_name,
        admin_email: data.admin_email,
        admin_phone: data.admin_phone || undefined,
        password: data.password,
        password_confirmation: data.password_confirmation,
      });

      localStorage.setItem("tenant", data.subdomain.toLowerCase());
      router.push("/setup/guided");
    } catch (err: unknown) {
      const axiosError = err as {
        response?: {
          status?: number;
          data?: { errors?: Record<string, string[]>; detail?: string };
        };
        request?: unknown;
      };

      if (
        !axiosError.response ||
        (axiosError.response.status && axiosError.response.status >= 500)
      ) {
        setServerError(
          t(
            "auth.server_error",
            "Unable to connect to the server. Please try again later.",
          ),
        );
      } else if (axiosError.response.data?.errors) {
        setFieldErrors(axiosError.response.data.errors);
        const errorFields = Object.keys(axiosError.response.data.errors);
        if (
          errorFields.some((f) =>
            ["organization_name", "organization_type", "subdomain"].includes(f),
          )
        ) {
          setStepIndex(0);
        } else if (
          errorFields.some((f) =>
            ["admin_name", "admin_email", "admin_phone"].includes(f),
          )
        ) {
          setStepIndex(1);
        } else {
          setStepIndex(2);
        }
      } else if (axiosError.response.data?.detail) {
        setServerError(axiosError.response.data.detail);
      } else {
        setServerError(
          t(
            "auth.registration_failed",
            "Registration failed. Please try again.",
          ),
        );
      }
    }
  }

  const stepLabels: Record<Step, string> = {
    organization: t("auth.step_organization", "Organization"),
    admin: t("auth.step_admin", "Admin Account"),
    security: t("auth.step_security", "Security"),
  };

  return (
    <div className="w-full max-w-lg mx-auto">
      <div className="mb-8">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary shadow-sm">
          <span className="text-base font-bold text-primary-foreground">E</span>
        </div>
        <h1 className="mt-6 text-2xl font-bold tracking-tight text-foreground">
          {t("auth.create_account", "Create your account")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t(
            "auth.create_subtitle",
            "Start your 6-month free trial. No credit card required.",
          )}
        </p>
      </div>

      {/* Step indicator */}
      <div className="mb-8 flex items-center">
        {steps.map((step, i) => (
          <div
            key={step}
            className="flex flex-1 items-center last:flex-initial"
          >
            <div className="flex flex-col items-center gap-2">
              <div
                className={`flex h-9 w-9 items-center justify-center rounded-full text-sm font-medium transition-all duration-200 ${
                  i < stepIndex
                    ? "bg-primary text-primary-foreground shadow-sm"
                    : i === stepIndex
                      ? "border-2 border-primary bg-primary/5 text-primary"
                      : "border-2 border-border text-muted-foreground"
                }`}
              >
                {i < stepIndex ? <Check className="h-4 w-4" /> : i + 1}
              </div>
              <span
                className={`text-[11px] font-medium whitespace-nowrap ${
                  i <= stepIndex ? "text-foreground" : "text-muted-foreground"
                }`}
              >
                {stepLabels[step]}
              </span>
            </div>
            {i < steps.length - 1 && (
              <div
                className={`mx-3 mb-5 h-0.5 flex-1 rounded-full transition-colors duration-200 ${
                  i < stepIndex ? "bg-primary" : "bg-border"
                }`}
              />
            )}
          </div>
        ))}
      </div>

      <form onSubmit={handleSubmit(onSubmit)}>
        {/* Step 1: Organization */}
        {currentStep === "organization" && (
          <div className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="org_name">
                {t("auth.org_name", "Organization Name")}
              </Label>
              <Input
                id="org_name"
                aria-invalid={hasError_organization_name || undefined}
                aria-describedby={
                  hasError_organization_name ? "org_name-error" : undefined
                }
                {...register("organization_name", {
                  onChange: (e) => handleOrgNameChange(e.target.value),
                })}
                minLength={2}
                autoFocus
              />
              {(errors.organization_name || fieldErrors.organization_name) && (
                <p
                  id="org_name-error"
                  role="alert"
                  className="text-xs text-destructive"
                >
                  {errors.organization_name
                    ? t(
                        errors.organization_name.message!,
                        "Organization name is required.",
                      )
                    : fieldErrors.organization_name[0]}
                </p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="org_type">
                {t("auth.org_type", "Organization Type")}
              </Label>
              <select
                id="org_type"
                {...register("organization_type")}
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              >
                {orgTypeKeys.map((key) => (
                  <option key={key} value={key}>
                    {t(`auth.org_type_${key}`)}
                  </option>
                ))}
              </select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="subdomain">
                {t("auth.subdomain", "Subdomain")}
              </Label>
              <div className="flex items-center gap-2">
                <div className="relative flex-1">
                  <Input
                    id="subdomain"
                    aria-invalid={hasError_subdomain || undefined}
                    aria-describedby={
                      hasError_subdomain ? "subdomain-error" : undefined
                    }
                    {...register("subdomain", {
                      onChange: (e) => handleSubdomainChange(e.target.value),
                    })}
                    minLength={3}
                    maxLength={63}
                    className="pr-8"
                  />
                  <div className="absolute inset-y-0 right-2 flex items-center">
                    {checkingSubdomain && (
                      <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                    )}
                    {!checkingSubdomain && subdomainAvailable === true && (
                      <Check className="h-4 w-4 text-status-success" />
                    )}
                    {!checkingSubdomain && subdomainAvailable === false && (
                      <X className="h-4 w-4 text-destructive" />
                    )}
                  </div>
                </div>
                <span className="shrink-0 text-sm text-muted-foreground">
                  .ethr.et
                </span>
              </div>
              {subdomainAvailable === false && (
                <p className="text-xs text-destructive">
                  {t(
                    "auth.subdomain_unavailable",
                    "This subdomain is not available",
                  )}
                </p>
              )}
              {(errors.subdomain || fieldErrors.subdomain) && (
                <p
                  id="subdomain-error"
                  role="alert"
                  className="text-xs text-destructive"
                >
                  {errors.subdomain
                    ? t(errors.subdomain.message!, "Subdomain is required.")
                    : fieldErrors.subdomain[0]}
                </p>
              )}
            </div>
          </div>
        )}

        {/* Step 2: Admin */}
        {currentStep === "admin" && (
          <div className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="admin_name">
                {t("auth.admin_name", "Full Name")}
              </Label>
              <Input
                id="admin_name"
                aria-invalid={hasError_admin_name || undefined}
                aria-describedby={
                  hasError_admin_name ? "admin_name-error" : undefined
                }
                {...register("admin_name")}
                minLength={2}
                autoFocus
              />
              {(errors.admin_name || fieldErrors.admin_name) && (
                <p
                  id="admin_name-error"
                  role="alert"
                  className="text-xs text-destructive"
                >
                  {errors.admin_name
                    ? t(errors.admin_name.message!, "Name is required.")
                    : fieldErrors.admin_name[0]}
                </p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="admin_email">
                {t("auth.admin_email", "Email Address")}
              </Label>
              <Input
                id="admin_email"
                aria-invalid={hasError_admin_email || undefined}
                aria-describedby={
                  hasError_admin_email ? "admin_email-error" : undefined
                }
                type="email"
                {...register("admin_email")}
                autoComplete="email"
              />
              {(errors.admin_email || fieldErrors.admin_email) && (
                <p
                  id="admin_email-error"
                  role="alert"
                  className="text-xs text-destructive"
                >
                  {errors.admin_email
                    ? t(errors.admin_email.message!, "Valid email is required.")
                    : fieldErrors.admin_email[0]}
                </p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="admin_phone">
                {t("auth.admin_phone", "Phone (Optional)")}
              </Label>
              <Input
                id="admin_phone"
                {...register("admin_phone")}
                placeholder="0912345678"
              />
              <p className="text-xs text-muted-foreground">
                {t(
                  "auth.admin_phone_hint",
                  "Local (0912345678) or international (+251912345678) format.",
                )}
              </p>
              {fieldErrors.admin_phone && (
                <p className="text-xs text-destructive">
                  {fieldErrors.admin_phone[0]}
                </p>
              )}
            </div>
          </div>
        )}

        {/* Step 3: Security */}
        {currentStep === "security" && (
          <div className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="password">{t("auth.password", "Password")}</Label>
              <div className="relative">
                <Input
                  id="password"
                  aria-invalid={hasError_password || undefined}
                  aria-describedby={
                    hasError_password ? "password-error" : undefined
                  }
                  type={showPassword ? "text" : "password"}
                  {...register("password")}
                  minLength={8}
                  autoFocus
                  className="pr-10"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  aria-label={
                    showPassword
                      ? t("auth.hide_password", "Hide password")
                      : t("auth.show_password", "Show password")
                  }
                  className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground transition-colors hover:text-foreground"
                >
                  {showPassword ? (
                    <EyeOff className="h-4 w-4" />
                  ) : (
                    <Eye className="h-4 w-4" />
                  )}
                </button>
              </div>
              {password.length > 0 && (
                <div className="space-y-1.5">
                  <div className="flex gap-1">
                    {[1, 2, 3, 4, 5].map((i) => (
                      <div
                        key={i}
                        className={`h-1 flex-1 rounded-full transition-colors ${
                          i <= passwordStrength.score
                            ? passwordStrength.color
                            : "bg-muted"
                        }`}
                      />
                    ))}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {passwordStrength.label}
                  </p>
                </div>
              )}
              {(errors.password || fieldErrors.password) && (
                <p
                  id="password-error"
                  role="alert"
                  className="text-xs text-destructive"
                >
                  {errors.password
                    ? t(
                        errors.password.message!,
                        "Password must be at least 8 characters.",
                      )
                    : fieldErrors.password[0]}
                </p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="password_confirmation">
                {t("auth.confirm_password", "Confirm Password")}
              </Label>
              <Input
                id="password_confirmation"
                aria-invalid={hasError_password_confirmation || undefined}
                aria-describedby={
                  hasError_password_confirmation
                    ? "password_confirmation-error"
                    : undefined
                }
                type="password"
                {...register("password_confirmation")}
              />
              {passwordConfirmation.length > 0 &&
                passwordConfirmation !== password && (
                  <p className="text-xs text-destructive">
                    {t("auth.passwords_no_match", "Passwords do not match")}
                  </p>
                )}
              {errors.password_confirmation && (
                <p
                  id="password_confirmation-error"
                  role="alert"
                  className="text-xs text-destructive"
                >
                  {t(
                    errors.password_confirmation.message!,
                    "Passwords do not match",
                  )}
                </p>
              )}
            </div>
          </div>
        )}

        {serverError && (
          <div className="mt-5 rounded-lg bg-destructive/10 p-3">
            <p className="text-sm text-destructive">{serverError}</p>
          </div>
        )}

        <div className="mt-8 flex gap-3">
          {stepIndex > 0 && (
            <Button
              type="button"
              variant="outline"
              className="flex-1 h-11"
              onClick={goBack}
              disabled={isSubmitting}
            >
              <ArrowLeft className="h-4 w-4" />
              {t("common.back", "Back")}
            </Button>
          )}
          {stepIndex < steps.length - 1 ? (
            <Button
              type="button"
              className="flex-1 h-11 shadow-sm"
              onClick={goNext}
              disabled={
                (currentStep === "organization" && !step1Valid) ||
                (currentStep === "admin" && !step2Valid)
              }
            >
              {t("common.next", "Next")}
              <ArrowRight className="h-4 w-4" />
            </Button>
          ) : (
            <Button
              type="submit"
              className="flex-1 h-11 shadow-sm"
              disabled={isSubmitting || !step3Valid}
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t("auth.creating_account", "Creating account...")}
                </>
              ) : (
                t("auth.create_free_account", "Create Free Account")
              )}
            </Button>
          )}
        </div>

        <p className="mt-6 text-center text-sm text-muted-foreground">
          {t("auth.has_account", "Already have an account?")}{" "}
          <Link
            href="/login"
            className="font-medium text-primary hover:underline"
          >
            {t("auth.sign_in_link", "Sign in")}
          </Link>
        </p>
      </form>
    </div>
  );
}
