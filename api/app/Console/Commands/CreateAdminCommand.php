<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Provisions a platform super admin. Referenced by the Quick Start in
 * DEPLOYMENT.md as the post-install step that creates the first login.
 *
 * Runs interactively (prompts for email + password) or fully non-interactively
 * for automated deploys:
 *
 *   php artisan ethr:create-admin --email=admin@ethr.et --password=... --no-interaction
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'ethr:create-admin
        {--email= : Super admin email address}
        {--password= : Super admin password (min 12 chars); prompted securely if omitted}
        {--force : Update the password if a super admin with this email already exists}';

    protected $description = 'Create (or update) a platform super admin account';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Super admin email');
        $password = $this->option('password') ?: $this->secret('Super admin password (min 12 chars)');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('email', $email)
            ->first();

        if ($existing !== null) {
            if (! $this->option('force') && ! $this->confirm("A super admin with {$email} already exists. Reset its password?")) {
                $this->warn('Aborted. No changes made.');

                return self::FAILURE;
            }

            $existing->update([
                'password' => $password,
                'role' => UserRole::SUPER_ADMIN,
                'status' => 'active',
            ]);

            $this->info("Super admin password updated for {$email}.");

            return self::SUCCESS;
        }

        User::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::SUPER_ADMIN,
            'status' => 'active',
            'locale' => 'en',
            'mfa_enabled' => false,
        ]);

        $this->info("Super admin created: {$email}");

        return self::SUCCESS;
    }
}
