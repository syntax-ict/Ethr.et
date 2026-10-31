<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use App\Http\Middleware\ResolveTenant;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\Auth\AuthIdentifierResolver;
use App\Services\CurrentTenant;
use App\Support\ThrottleIdentity;
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
            // `identifier` is the general login value (email, phone, or employee
            // number, per the tenant's enabled types). `email` is kept as an
            // accepted alias so existing clients keep working unchanged.
            'identifier' => ['required_without:email', 'nullable', 'string'],
            'email' => ['required_without:identifier', 'nullable', 'string'],
            'password' => ['required', 'string'],
            // Read by the ResolveTenant middleware when the request is not on a
            // tenant subdomain, ahead of any X-Tenant header.
            'tenant' => ['nullable', 'string'],
        ];
    }

    /** The raw login value, from `identifier` or the `email` alias. */
    public function loginValue(): string
    {
        return trim((string) ($this->input('identifier') ?? $this->input('email') ?? ''));
    }

    /** Validation-error key: the field the client sent, for backward compatibility. */
    private function errorField(): string
    {
        return $this->input('identifier') !== null ? 'identifier' : 'email';
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $value = $this->loginValue();

        // Platform super admins have no tenant (tenant_id is null by design —
        // see CLAUDE.md's global model list), so they're invisible to the
        // tenant-scoped lookup below even when a tenant WAS resolved (e.g. a
        // subdomain field the login form still shows and the user filled in
        // out of habit). Check for a super admin match first, independent of
        // tenant resolution, before falling through to the normal flow. Super
        // admins always authenticate by email.
        // `email_normalized`, not `LOWER(email)`: a predicate on a function of a
        // column cannot use an index on that column, and this lookup runs on
        // every login attempt in the system. See BASELINE.md §15e.
        $superAdmin = User::withoutGlobalScopes()
            ->where('email_normalized', mb_strtolower($value))
            ->whereNull('tenant_id')
            ->where('role', UserRole::SUPER_ADMIN)
            ->first();

        if ($superAdmin && Hash::check($this->input('password'), $superAdmin->password)) {
            Auth::login($superAdmin);
            RateLimiter::clear($this->throttleKey());

            return;
        }

        // On the platform host only a super admin can sign in, so a miss above
        // is simply wrong credentials. Asking for an organisation there sends
        // the operator looking for a field the console does not have.
        if ($this->attributes->get(ResolveTenant::PLATFORM_HOST) === true) {
            RateLimiter::hit($this->throttleKey(), 300);

            throw ValidationException::withMessages([
                $this->errorField() => [__('auth.failed')],
            ]);
        }

        $currentTenant = app(CurrentTenant::class);

        if (! $currentTenant->resolved()) {
            throw ValidationException::withMessages([
                'tenant' => [__('Organization is required. Provide your subdomain.')],
            ]);
        }

        // Resolve the login value against the identifier types this tenant has
        // enabled (email by default; optionally phone / employee number). The
        // resolver is tenant-scoped and denies ambiguous identifiers, so a value
        // can never authenticate the wrong account. Password is still verified
        // here, in constant time via Hash::check.
        $tenant = $currentTenant->get();
        // Read settings via getAttribute so the array-cast's loose static type
        // (array|string) doesn't defeat the offset access.
        $rawSettings = $tenant?->getAttribute('settings');
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $enabledTypes = AuthIdentifierResolver::sanitizeTypes(
            (array) ($settings['login_identifiers'] ?? AuthIdentifierResolver::DEFAULT)
        );

        $user = app(AuthIdentifierResolver::class)->resolve(
            (int) $currentTenant->id(),
            $value,
            $enabledTypes,
        );

        // An unknown identifier still pays for one bcrypt: answering before any
        // hashing told a timing attacker which accounts exist (security review
        // 2026-10-30). Not a second one when the super admin check above has
        // already hashed, or the platform admin's address took twice as long
        // as any other on every organisation's sign-in page.
        if (! $user && $superAdmin === null) {
            Hash::make(Str::random(40));
        }

        if (! $user || ! Hash::check($this->input('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey(), 300);

            // Recorded only when the account resolved: `login_histories.user_id`
            // is a non-nullable FK, and writing a row for an unknown identifier
            // would also turn the history into a probe log of guessed usernames.
            if ($user) {
                LoginHistory::record($user, 'failed', 'invalid_password');
            }

            // A single generic message regardless of identifier type — no user
            // enumeration, no leak of which types are enabled. Reported under the
            // field the client actually sent, so email-based clients keep getting
            // an `email` error (backward compatible).
            throw ValidationException::withMessages([
                $this->errorField() => [__('auth.failed')],
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

        return Str::transliterate(ThrottleIdentity::of($this->loginValue()).'|'.$tenant.'|'.$this->ip());
    }
}
