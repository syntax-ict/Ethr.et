<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Tenant identifier may be supplied here as a fallback when the
            // request isn't already on a tenant subdomain or carrying X-Tenant.
            // The ResolveTenant middleware reads this field too.
            'tenant' => ['nullable', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Platform super admins have no tenant (tenant_id is null by design —
        // see CLAUDE.md's global model list), so they're invisible to the
        // tenant-scoped lookup below even when a tenant WAS resolved (e.g. a
        // subdomain field the login form still shows and the user filled in
        // out of habit). Check for a super admin match first, independent of
        // tenant resolution, before falling through to the normal flow.
        $superAdmin = User::withoutGlobalScopes()
            ->where('email', $this->input('email'))
            ->whereNull('tenant_id')
            ->where('role', UserRole::SUPER_ADMIN)
            ->first();

        if ($superAdmin && Hash::check($this->input('password'), $superAdmin->password)) {
            Auth::login($superAdmin);
            RateLimiter::clear($this->throttleKey());

            return;
        }

        $currentTenant = app(CurrentTenant::class);

        if (! $currentTenant->resolved()) {
            throw ValidationException::withMessages([
                'tenant' => [__('Organization is required. Provide your subdomain.')],
            ]);
        }

        // Scope the user lookup to the resolved tenant. Cross-tenant email
        // collisions are allowed by design — same email can exist as separate
        // users in different tenants.
        $user = User::withoutGlobalScopes()
            ->where('email', $this->input('email'))
            ->where('tenant_id', $currentTenant->id())
            ->first();

        if (! $user || ! Hash::check($this->input('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey(), 300);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        Auth::login($user);

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        // Rate limiting is handled by the RateLimitLoginAttempts middleware
        // which returns a proper 429 response. This is a no-op to avoid
        // a duplicate 422 from firing before the middleware's 429.
    }

    public function throttleKey(): string
    {
        $tenant = app(CurrentTenant::class)->resolved()
            ? app(CurrentTenant::class)->id()
            : 'no-tenant';

        return Str::transliterate(Str::lower($this->string('email')).'|'.$tenant.'|'.$this->ip());
    }
}
