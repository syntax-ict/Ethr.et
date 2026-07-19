"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Eye, EyeOff, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { apiClient } from "@/api/client";

function detectTenantFromHost(): string | null {
  if (typeof window === "undefined") return null;
  const host = window.location.host;
  const parts = host.split(".");
  // Production: acme.ethr.et → 'acme'. Dev: localhost/127.0.0.1 → null.
  if (parts.length >= 3) return parts[0];
  if (
    parts.length === 2 &&
    !["localhost", "test"].includes(parts[1].split(":")[0])
  ) {
    return parts[0];
  }
  return null;
}

export default function LoginPage() {
  const router = useRouter();
  const subdomainFromHost =
    typeof window !== "undefined" ? detectTenantFromHost() : null;
  const [tenant, setTenant] = useState(() =>
    typeof window !== "undefined" ? (localStorage.getItem("tenant") ?? "") : "",
  );
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  // When on a tenant subdomain, the field is hidden and we use the URL.
  const effectiveTenant = subdomainFromHost ?? tenant.trim().toLowerCase();
  const tenantFieldVisible = !subdomainFromHost;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError("");

    if (!effectiveTenant) {
      setError("Please enter your organization subdomain.");
      setLoading(false);
      return;
    }

    try {
      // Persist tenant BEFORE the call so the apiClient interceptor sends X-Tenant.
      localStorage.setItem("tenant", effectiveTenant);

      const response = await apiClient.post("/auth/login", {
        email,
        password,
        tenant: effectiveTenant, // also sent in body as defense-in-depth
      });

      if (response.data.mfa_required) {
        localStorage.setItem("mfa_token", response.data.access_token);
        router.push("/login/mfa");
        return;
      }

      localStorage.setItem("access_token", response.data.access_token);
      router.push("/dashboard");
    } catch (err: unknown) {
      const axiosError = err as { response?: { data?: { detail?: string } } };
      setError(
        axiosError.response?.data?.detail ||
          "Invalid credentials. Please try again.",
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="w-full max-w-sm">
      <div className="mb-8 text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
          <span className="text-lg font-bold text-primary-foreground">E</span>
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          Sign in to ETHR
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Enter your credentials to access your account
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        {tenantFieldVisible && (
          <div className="space-y-2">
            <Label htmlFor="tenant">Organization subdomain</Label>
            <div className="flex items-center rounded-md border border-input focus-within:ring-2 focus-within:ring-ring">
              <Input
                id="tenant"
                value={tenant}
                onChange={(e) => setTenant(e.target.value)}
                placeholder="acme"
                required
                autoComplete="organization"
                className="border-0 focus-visible:ring-0"
              />
              <span className="px-3 text-sm text-muted-foreground border-l">
                .ethr.et
              </span>
            </div>
            <p className="text-xs text-muted-foreground">
              Don&apos;t know your subdomain? Check the invitation email or ask
              your administrator.
            </p>
          </div>
        )}
        <div className="space-y-2">
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoComplete="email"
          />
        </div>

        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label htmlFor="password">Password</Label>
            <Link
              href="/login/forgot"
              className="text-xs text-primary hover:underline"
            >
              Forgot password?
            </Link>
          </div>
          <div className="relative">
            <Input
              id="password"
              type={showPassword ? "text" : "password"}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              autoComplete="current-password"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              aria-label={showPassword ? "Hide password" : "Show password"}
              className="absolute inset-y-0 right-2 flex items-center text-muted-foreground hover:text-foreground"
            >
              {showPassword ? (
                <EyeOff className="h-4 w-4" />
              ) : (
                <Eye className="h-4 w-4" />
              )}
            </button>
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
              Signing in...
            </>
          ) : (
            "Sign In"
          )}
        </Button>

        <p className="text-center text-sm text-muted-foreground">
          Don&apos;t have an account?{" "}
          <Link
            href="/register"
            className="font-medium text-primary hover:underline"
          >
            Start free trial
          </Link>
        </p>
      </form>
    </div>
  );
}
