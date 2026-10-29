<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\AccountActivationCode;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Sms\ConfiguredSmsSender;
use App\Services\UserProvisioningService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * In-person activation for an employee who has no email and no phone.
 *
 * HR checks the person's identity face to face and issues a one-time code,
 * shown once as a QR and as typed characters. The employee redeems it on their
 * own device and chooses their own password; HR never sees it. A lost password
 * is recovered the same way, with a fresh code.
 *
 * The code is 12 Crockford base32 characters (60 bits), kept only as an HMAC
 * under the application key. It is spent by one conditional update, so two
 * requests cannot both redeem it. Every way a redemption can fail looks the
 * same to the caller: nothing confirms that an employee or a code exists.
 */
final class ActivationCodeService
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const CODE_LENGTH = 12;

    public const MAX_FAILED_ATTEMPTS = 5;

    public const DEFAULT_TTL_HOURS = 24;

    public const MAX_TTL_HOURS = 72;

    public const DOCUMENT_TYPES = ['national_id', 'kebele_id', 'passport', 'driving_licence', 'employer_id'];

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly UserProvisioningService $provisioning,
        private readonly ConfiguredSmsSender $sms,
    ) {}

    /**
     * Issue a code for this employee, replacing any live one. Returns the
     * plaintext code, which exists nowhere else and cannot be read back.
     *
     * @return array{code: string, record: AccountActivationCode}
     *
     * @throws ActivationCodeRefused
     */
    public function issue(Employee $employee, User $actor, string $documentType, ?string $note): array
    {
        $tenant = $this->currentTenant->get();
        if ($tenant === null || $employee->tenant_id !== $tenant->id) {
            throw new ActivationCodeRefused('tenant');
        }

        if (! in_array($documentType, self::DOCUMENT_TYPES, true)) {
            throw new ActivationCodeRefused('document');
        }

        $user = $employee->user_id !== null
            ? User::query()->find($employee->user_id)
            : null;

        $this->guardTarget($actor, $user);

        if ($this->hasContact($employee, $user)) {
            throw new ActivationCodeRefused('contact');
        }

        if (! $this->canSignInAfterwards($user)) {
            throw new ActivationCodeRefused('identifier');
        }

        /** @var array{code: string, record: AccountActivationCode} $issued */
        $issued = DB::transaction(function () use ($employee, $actor, $documentType, $note, $tenant, $user): array {
            $user ??= $this->provisioning->provisionWithoutContact($employee, $actor->id);

            $this->revokeLive($user);

            $plain = $this->generate();

            $record = AccountActivationCode::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'employee_id' => $employee->id,
                'purpose' => $user->status === 'invited'
                    ? AccountActivationCode::ACTIVATION
                    : AccountActivationCode::RECOVERY,
                'code_hash' => $this->hash($plain),
                'issued_by' => $actor->id,
                'document_type' => $documentType,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                'expires_at' => Carbon::now('UTC')->addHours($this->ttlHours()),
            ]);

            AuditLog::record('user.activation_code_issued', $user, [
                'employee_id' => $employee->public_id,
                'purpose' => $record->purpose,
                'document_type' => $documentType,
                'expires_at' => $record->expires_at->toIso8601String(),
            ]);

            return ['code' => $plain, 'record' => $record];
        });

        return $issued;
    }

    /** Withdraw the user's live code, if any. Returns whether one was live. */
    public function revoke(User $user, User $actor): bool
    {
        $revoked = $this->revokeLive($user);

        if ($revoked) {
            AuditLog::record('user.activation_code_revoked', $user, ['revoked_by' => $actor->id]);
        }

        return $revoked;
    }

    /** The user's latest code row, for the HR status line. It never carries the code. */
    public function latestFor(User $user): ?AccountActivationCode
    {
        return AccountActivationCode::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    /** 'none', 'live', 'used', 'expired', 'revoked' or 'locked'. */
    public function stateOf(?AccountActivationCode $record): string
    {
        if ($record === null) {
            return 'none';
        }

        if ($record->consumed_at !== null) {
            return 'used';
        }

        if ($record->revoked_at !== null) {
            return $record->failed_attempts >= self::MAX_FAILED_ATTEMPTS ? 'locked' : 'revoked';
        }

        return $record->expires_at->isPast() ? 'expired' : 'live';
    }

    /**
     * Spend the code and set the employee's own password. Returns the user, or
     * null for every kind of failure so the caller cannot tell them apart.
     */
    public function redeem(string $employeeCode, string $code, string $password): ?User
    {
        $tenant = $this->currentTenant->get();
        $normalised = self::normalise($code);
        $hash = $this->hash($normalised ?? '');

        if ($tenant === null || $normalised === null || trim($employeeCode) === '') {
            return null;
        }

        $employee = Employee::query()
            ->where('tenant_id', $tenant->id)
            ->where('employee_code_normalized', mb_strtolower(trim($employeeCode)))
            ->first();

        $user = $employee?->user_id !== null ? User::query()->find($employee->user_id) : null;

        $record = $user !== null
            ? AccountActivationCode::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', Carbon::now('UTC'))
                ->latest('id')
                ->first()
            : null;

        if ($user === null || $record === null) {
            return null;
        }

        if (! hash_equals($record->code_hash, $hash)) {
            $this->recordWrongGuess($record, $user);

            return null;
        }

        return DB::transaction(function () use ($record, $user, $password): ?User {
            $claimed = AccountActivationCode::query()
                ->where('id', $record->id)
                ->where('tenant_id', $record->tenant_id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', Carbon::now('UTC'))
                ->update(['consumed_at' => Carbon::now('UTC')]) === 1;

            if (! $claimed) {
                return null;
            }

            $wasInvited = $user->status === 'invited';

            $updates = [
                'password' => Hash::make($password),
                'password_changed_at' => now(),
            ];

            if ($wasInvited) {
                $updates['status'] = 'active';
                $updates['activated_at'] = now();
            }

            $user->update($updates);
            $user->tokens()->delete();

            AuditLog::record('user.activation_code_redeemed', $user, ['purpose' => $record->purpose]);

            if ($wasInvited) {
                AuditLog::record('user.activated', $user);
            }

            return $user;
        });
    }

    /** Delete spent and long-expired rows; run by the scheduler. */
    public function purge(): int
    {
        return AccountActivationCode::withoutGlobalScope('tenant')
            ->where('expires_at', '<', Carbon::now('UTC')->subDays(30))
            ->delete();
    }

    /**
     * Whether the employee could sign in once activated: the tenant allows the
     * employee code, or the user has a username.
     */
    public function canSignInAfterwards(?User $user): bool
    {
        $tenant = $this->currentTenant->get();
        $raw = $tenant?->getAttribute('settings');
        $settings = is_array($raw) ? $raw : [];
        $types = AuthIdentifierResolver::sanitizeTypes((array) ($settings['login_identifiers'] ?? []));

        if (in_array(AuthIdentifierResolver::EMPLOYEE_CODE, $types, true)) {
            return true;
        }

        return in_array(AuthIdentifierResolver::USERNAME, $types, true)
            && $user !== null
            && trim((string) $user->username) !== '';
    }

    /** Canonical form of what a person typed or scanned, or null when it cannot be a code. */
    public static function normalise(string $code): ?string
    {
        $code = strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');
        // Crockford: a person reading the code aloud cannot tell O from 0 or I/L from 1.
        $code = strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);

        if (strlen($code) !== self::CODE_LENGTH || strspn($code, self::ALPHABET) !== self::CODE_LENGTH) {
            return null;
        }

        return $code;
    }

    /** 'ABCD-EFGH-JKMN' for reading aloud and printing. */
    public static function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function ttlHours(): int
    {
        $raw = $this->currentTenant->get()?->getAttribute('settings');
        $settings = is_array($raw) ? $raw : [];
        $hours = (int) ($settings['activation_code_hours'] ?? self::DEFAULT_TTL_HOURS);

        return max(1, min(self::MAX_TTL_HOURS, $hours));
    }

    private function revokeLive(User $user): bool
    {
        return AccountActivationCode::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now('UTC')]) > 0;
    }

    private function recordWrongGuess(AccountActivationCode $record, User $user): void
    {
        AccountActivationCode::query()
            ->where('id', $record->id)
            ->where('tenant_id', $record->tenant_id)
            ->increment('failed_attempts');

        $attempts = (int) AccountActivationCode::query()
            ->where('id', $record->id)
            ->where('tenant_id', $record->tenant_id)
            ->value('failed_attempts');

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            AccountActivationCode::query()
                ->where('id', $record->id)
                ->where('tenant_id', $record->tenant_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now('UTC')]);

            AuditLog::record('user.activation_code_locked', $user, ['attempts' => $attempts]);

            return;
        }

        AuditLog::record('user.activation_code_failed', $user, ['attempts' => $attempts]);
    }

    /** Whether a link or a text can reach this employee, so no in-person code is issued. */
    public function reachable(Employee $employee, ?User $user): bool
    {
        return $this->hasContact($employee, $user);
    }

    /**
     * An email, or a phone a text can actually reach, means the normal
     * activation link or sign-in code can reach them.
     *
     * A phone counted on its own until 2026-10-28, and SMS is not live: a
     * person with only a phone could get neither a link nor a text nor an
     * in-person code. It counts now only while texts would reach it: for an
     * account still to be activated, once text invitations are on; for an
     * active one, whenever sign-in codes can be sent.
     */
    private function hasContact(Employee $employee, ?User $user): bool
    {
        foreach ([$employee->email, $user?->email] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        $hasPhone = false;
        foreach ([$employee->phone, $user?->phone] as $value) {
            $hasPhone = $hasPhone || (is_string($value) && trim($value) !== '');
        }

        if (! $hasPhone) {
            return false;
        }

        return $user !== null && $user->status === 'active'
            ? $this->sms->isAvailable()
            : $this->sms->invitationsEnabled();
    }

    /**
     * Who may be issued a code: someone strictly below the actor, never an
     * administrator, never the actor themselves, and only an account that can
     * still be activated or recovered.
     *
     * @throws ActivationCodeRefused
     */
    private function guardTarget(User $actor, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        if ($user->id === $actor->id) {
            throw new ActivationCodeRefused('self');
        }

        if ($user->role->level() >= $actor->role->level() || $user->role->isAtLeast(UserRole::TENANT_ADMIN)) {
            throw new ActivationCodeRefused('role');
        }

        if (! in_array($user->status, ['invited', 'active'], true)) {
            throw new ActivationCodeRefused('status');
        }
    }
}
