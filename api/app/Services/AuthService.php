<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Events\TenantCreated;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function registerTenant(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['organization_name'],
                'subdomain' => $data['subdomain'],
                'type' => $data['organization_type'] ?? null,
                'status' => TenantStatus::TRIAL,
                'trial_ends_at' => now()->addMonths(6),
            ]);

            $this->currentTenant->set($tenant);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['admin_name'] ?? null,
                'email' => $data['admin_email'],
                'phone' => $data['admin_phone'] ?? null,
                'password' => Hash::make($data['password']),
                'role' => UserRole::TENANT_ADMIN,
                'status' => 'active',
                'locale' => 'en',
            ]);

            $starterPlan = Plan::where('slug', 'starter')->first();
            if ($starterPlan) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $starterPlan->id,
                    'status' => 'trial',
                    'current_period_start' => now(),
                    'current_period_end' => $tenant->trial_ends_at,
                ]);
            }

            AuditLog::record('tenant.registered', $tenant, [
                'admin_email' => $user->email,
            ]);

            $token = $user->createToken('auth', ['*'], now()->addMinutes(15));

            TenantCreated::dispatch($tenant, $user);

            return [
                'user' => $user,
                'tenant' => $tenant,
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => 900,
            ];
        });
    }

    public function login(User $user): array
    {
        $user->tokens()->delete();

        $token = $user->createToken('auth', ['*'], now()->addMinutes(15));

        $user->update(['last_login_at' => now()]);

        AuditLog::record('user.login', $user);

        return [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'mfa_required' => $user->mfa_enabled,
        ];
    }

    public function refreshToken(User $user): array
    {
        $currentToken = $user->currentAccessToken();

        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }

        $token = $user->createToken('auth', ['*'], now()->addMinutes(15));

        return [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
        ];
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        AuditLog::record('user.logout', $user);
    }
}
