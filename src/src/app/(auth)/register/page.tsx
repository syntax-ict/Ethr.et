"use client";

import { useState, useCallback } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Eye, EyeOff, Check, X, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { apiClient } from "@/api/client";

const orgTypes = [
  { value: "government", label: "Government Office" },
  { value: "bank", label: "Bank / Financial" },
  { value: "hospital", label: "Hospital / Healthcare" },
  { value: "manufacturing", label: "Manufacturing" },
  { value: "ngo", label: "NGO" },
  { value: "hotel", label: "Hotel / Hospitality" },
  { value: "university", label: "University / Education" },
  { value: "general", label: "General Company" },
];

function slugify(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 63);
}

function getPasswordStrength(password: string): {
  score: number;
  label: string;
  color: string;
} {
  let score = 0;
  if (password.length >= 8) score++;
  if (password.length >= 12) score++;
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
  if (/\d/.test(password)) score++;
  if (/[^a-zA-Z0-9]/.test(password)) score++;

  if (score <= 1) return { score, label: "Weak", color: "bg-red-500" };
  if (score <= 2) return { score, label: "Fair", color: "bg-amber-500" };
  if (score <= 3) return { score, label: "Good", color: "bg-yellow-500" };
  return { score, label: "Strong", color: "bg-emerald-500" };
}

export default function RegisterPage() {
  const router = useRouter();
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const [orgName, setOrgName] = useState("");
  const [orgType, setOrgType] = useState("general");
  const [subdomain, setSubdomain] = useState("");
  const [subdomainAvailable, setSubdomainAvailable] = useState<boolean | null>(
    null,
  );
  const [checkingSubdomain, setCheckingSubdomain] = useState(false);

  const [adminName, setAdminName] = useState("");
  const [adminEmail, setAdminEmail] = useState("");
  const [adminPhone, setAdminPhone] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");

  const passwordStrength = getPasswordStrength(password);

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
    setOrgName(value);
    const suggested = slugify(value);
    setSubdomain(suggested);
    if (suggested.length >= 3) {
      checkSubdomain(suggested);
    }
  }

  function handleSubdomainChange(value: string) {
    const cleaned = value.toLowerCase().replace(/[^a-z0-9-]/g, "");
    setSubdomain(cleaned);
    setSubdomainAvailable(null);
    if (cleaned.length >= 3) {
      const timeout = setTimeout(() => checkSubdomain(cleaned), 500);
      return () => clearTimeout(timeout);
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError("");
    setFieldErrors({});

    try {
      const response = await apiClient.post("/auth/register", {
        organization_name: orgName,
        organization_type: orgType,
        subdomain,
        admin_name: adminName,
        admin_email: adminEmail,
        admin_phone: adminPhone || undefined,
        password,
        password_confirmation: passwordConfirmation,
      });

      if (response.data.access_token) {
        localStorage.setItem("access_token", response.data.access_token);
      }
      // Persist tenant context so all subsequent requests send X-Tenant.
      localStorage.setItem("tenant", subdomain.toLowerCase());

      router.push("/setup");
    } catch (err: unknown) {
      const axiosError = err as {
        response?: {
          data?: { errors?: Record<string, string[]>; detail?: string };
        };
      };
      if (axiosError.response?.data?.errors) {
        setFieldErrors(axiosError.response.data.errors);
      } else if (axiosError.response?.data?.detail) {
        setError(axiosError.response.data.detail);
      } else {
        setError("Registration failed. Please try again.");
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="w-full max-w-lg">
      <div className="mb-8 text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
          <span className="text-lg font-bold text-primary-foreground">E</span>
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          Create your account
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Start your 6-month free trial. No credit card required.
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-5">
        {/* Organization */}
        <div className="space-y-4 rounded-lg border p-4">
          <h2 className="text-sm font-semibold text-foreground">
            Organization
          </h2>

          <div className="space-y-2">
            <Label htmlFor="org_name">Organization Name</Label>
            <Input
              id="org_name"
              value={orgName}
              onChange={(e) => handleOrgNameChange(e.target.value)}
              required
              minLength={2}
            />
            {fieldErrors.organization_name && (
              <p className="text-xs text-destructive">
                {fieldErrors.organization_name[0]}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="org_type">Organization Type</Label>
            <select
              id="org_type"
              value={orgType}
              onChange={(e) => setOrgType(e.target.value)}
              className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            >
              {orgTypes.map((type) => (
                <option key={type.value} value={type.value}>
                  {type.label}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-2">
            <Label htmlFor="subdomain">Subdomain</Label>
            <div className="flex items-center gap-2">
              <div className="relative flex-1">
                <Input
                  id="subdomain"
                  value={subdomain}
                  onChange={(e) => handleSubdomainChange(e.target.value)}
                  required
                  minLength={3}
                  maxLength={63}
                />
                <div className="absolute inset-y-0 right-2 flex items-center">
                  {checkingSubdomain && (
                    <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                  )}
                  {!checkingSubdomain && subdomainAvailable === true && (
                    <Check className="h-4 w-4 text-emerald-500" />
                  )}
                  {!checkingSubdomain && subdomainAvailable === false && (
                    <X className="h-4 w-4 text-red-500" />
                  )}
                </div>
              </div>
              <span className="text-sm text-muted-foreground">.ethr.et</span>
            </div>
            {subdomainAvailable === false && (
              <p className="text-xs text-destructive">
                This subdomain is not available
              </p>
            )}
            {fieldErrors.subdomain && (
              <p className="text-xs text-destructive">
                {fieldErrors.subdomain[0]}
              </p>
            )}
          </div>
        </div>

        {/* Admin */}
        <div className="space-y-4 rounded-lg border p-4">
          <h2 className="text-sm font-semibold text-foreground">
            Admin Account
          </h2>

          <div className="space-y-2">
            <Label htmlFor="admin_name">Full Name</Label>
            <Input
              id="admin_name"
              value={adminName}
              onChange={(e) => setAdminName(e.target.value)}
              required
              minLength={2}
            />
            {fieldErrors.admin_name && (
              <p className="text-xs text-destructive">
                {fieldErrors.admin_name[0]}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="admin_email">Email Address</Label>
            <Input
              id="admin_email"
              type="email"
              value={adminEmail}
              onChange={(e) => setAdminEmail(e.target.value)}
              required
            />
            {fieldErrors.admin_email && (
              <p className="text-xs text-destructive">
                {fieldErrors.admin_email[0]}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="admin_phone">Phone (Optional)</Label>
            <Input
              id="admin_phone"
              value={adminPhone}
              onChange={(e) => setAdminPhone(e.target.value)}
              placeholder="+251911234567"
            />
            {fieldErrors.admin_phone && (
              <p className="text-xs text-destructive">
                {fieldErrors.admin_phone[0]}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">Password</Label>
            <div className="relative">
              <Input
                id="password"
                type={showPassword ? "text" : "password"}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={8}
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute inset-y-0 right-2 flex items-center text-muted-foreground hover:text-foreground"
              >
                {showPassword ? (
                  <EyeOff className="h-4 w-4" />
                ) : (
                  <Eye className="h-4 w-4" />
                )}
              </button>
            </div>
            {password.length > 0 && (
              <div className="space-y-1">
                <div className="flex gap-1">
                  {[1, 2, 3, 4, 5].map((i) => (
                    <div
                      key={i}
                      className={`h-1 flex-1 rounded-full ${
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
            {fieldErrors.password && (
              <p className="text-xs text-destructive">
                {fieldErrors.password[0]}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="password_confirmation">Confirm Password</Label>
            <Input
              id="password_confirmation"
              type="password"
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              required
            />
          </div>
        </div>

        {error && (
          <div className="rounded-lg bg-destructive/10 p-3">
            <p className="text-sm text-destructive">{error}</p>
          </div>
        )}

        <Button type="submit" className="w-full" disabled={loading}>
          {loading ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" />
              Creating account...
            </>
          ) : (
            "Create Free Account"
          )}
        </Button>

        <p className="text-center text-sm text-muted-foreground">
          Already have an account?{" "}
          <Link
            href="/login"
            className="font-medium text-primary hover:underline"
          >
            Sign in
          </Link>
        </p>
      </form>
    </div>
  );
}
