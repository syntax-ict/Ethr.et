<?php

declare(strict_types=1);

namespace App\Services\Attendance\Device;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceSource;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Exceptions\DeviceBindingRefused;
use App\Models\AttendanceDevice;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\BranchAttendanceOverride;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendanceDeviceDecidedNotification;
use App\Notifications\AttendanceDeviceRequestedNotification;
use App\Services\Attendance\AttendancePlaces;
use App\Services\Attendance\AttendancePolicy;
use App\Services\Auth\DeviceFingerprint;
use App\Support\FrontendUrl;
use App\Support\PunchSignals;
use App\Support\TenancyDomain;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Which phone may punch for whom. Each employee has at most one active
 * passkey; a self-service punch carries a fresh signature from it, and the
 * gate says what it found (DeviceVerdict). The engine decides what a verdict
 * means under the organisation's `device_binding` (AttendanceEngine).
 *
 * A friend's phone, a second phone, another browser or a private window holds
 * no passkey for this employee, so its punch is `unregistered`; a passkey
 * belonging to someone else is `foreign`. The first phone registers by
 * itself only at work (inside the branch or a site); a replacement, or a
 * registration from anywhere else, waits for an approver.
 *
 * What it cannot see: an employee handing their own unlocked phone to a
 * friend. The selfie, liveness and the review queue answer that.
 */
final class AttendanceDeviceGate
{
    /** Sources a person punches from their own phone or browser. */
    public const SELF_SERVICE = [
        AttendanceSource::WEB,
        AttendanceSource::MOBILE,
        AttendanceSource::QR,
        AttendanceSource::OFFLINE_MOBILE,
    ];

    public function __construct(
        private readonly AttendancePolicy $policy,
        private readonly AttendancePlaces $places,
        private readonly PasskeyVerifier $passkeys,
        private readonly DeviceChallenges $challenges,
        private readonly DeviceFingerprint $fingerprint,
    ) {}

    /** The binding that applies to this person: off, flag or enforce. */
    public function binding(Employee $employee, ?AttendanceSetting $policy = null): string
    {
        $binding = (string) (($policy ?? $this->policy->for($employee))->device_binding ?? 'off');

        return in_array($binding, ['off', 'flag', 'enforce'], true) ? $binding : 'off';
    }

    /** The relying party id passkeys are registered for. */
    public function rpId(?AttendanceSetting $organisation = null): string
    {
        $configured = trim((string) (($organisation ?? $this->policy->organisation())->attendance_rp_id ?? ''));

        return strtolower($configured !== '' ? $configured : (TenancyDomain::root() ?? (string) request()->getHost()));
    }

    /** Whether a request's host can use passkeys registered for $rpId. */
    public function hostAllowed(Request $request, string $rpId): bool
    {
        $host = strtolower($request->getHost());

        return $host === $rpId || str_ends_with($host, '.'.$rpId);
    }

    /** The employee's device in use or waiting, newest first. */
    public function deviceFor(Employee $employee, string $status): ?AttendanceDevice
    {
        return AttendanceDevice::query()
            ->where('employee_id', $employee->id)
            ->where('status', $status)
            ->latest('id')
            ->first();
    }

    /**
     * The verdict for an online punch.
     *
     * @param  array<string, mixed>|null  $proof  the browser's AuthenticationResponseJSON
     */
    public function verdict(Employee $employee, Request $request, ?array $proof, AttendanceSource $source): DeviceVerdict
    {
        $policy = $this->policy->for($employee);
        $binding = $this->binding($employee, $policy);
        if ($binding === 'off' || ! in_array($source, self::SELF_SERVICE, true)) {
            return new DeviceVerdict(DeviceVerdict::OFF, $binding);
        }

        $rpId = $this->rpId();
        $found = $proof === null ? null : $this->verifyProof($employee, $proof, $rpId, $request);
        if ($found !== null && $found->status === DeviceVerdict::VERIFIED) {
            return $found;
        }

        if ($this->exempt($employee)) {
            return new DeviceVerdict(DeviceVerdict::EXEMPT, $binding);
        }
        if ($source === AttendanceSource::WEB && $this->onOfficeNetwork($request, $policy)) {
            return new DeviceVerdict(DeviceVerdict::OFFICE_PC, $binding);
        }
        if (! $this->hostAllowed($request, $rpId)) {
            return new DeviceVerdict(DeviceVerdict::WRONG_HOST, $binding, canonicalUrl: $this->canonicalUrl($employee));
        }
        if ($found !== null) {
            return $found;
        }

        return new DeviceVerdict($this->deviceFor($employee, AttendanceDevice::PENDING) !== null
            ? DeviceVerdict::PENDING
            : DeviceVerdict::UNREGISTERED, $binding);
    }

    /**
     * For an action that is not a punch (a field visit): refuse it under
     * `enforce` when the verdict does not allow it.
     *
     * @param  array<string, mixed>|null  $proof
     */
    public function enforce(Employee $employee, Request $request, ?array $proof, AttendanceSource $source): DeviceVerdict
    {
        $verdict = $this->verdict($employee, $request, $proof, $source);
        if ($verdict->binding === 'enforce' && ! $verdict->allowed()) {
            throw DeviceBindingRefused::from($verdict);
        }

        return $verdict;
    }

    /**
     * Options for the browser to create a passkey for this employee.
     *
     * @return array<string, mixed>
     */
    public function registrationOptions(Employee $employee, Request $request): array
    {
        $rpId = $this->rpId();
        if (! $this->hostAllowed($request, $rpId)) {
            throw DeviceBindingRefused::from(new DeviceVerdict(DeviceVerdict::WRONG_HOST, canonicalUrl: $this->canonicalUrl($employee)));
        }

        $tenant = Tenant::query()->find($employee->tenant_id);
        $exclude = AttendanceDevice::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [AttendanceDevice::ACTIVE, AttendanceDevice::PENDING])
            ->pluck('credential_id')
            ->all();

        return $this->passkeys->registrationOptions(
            $rpId,
            (string) ($tenant->name ?? 'ETHR'),
            (string) $employee->public_id,
            (string) ($employee->employee_code ?? $employee->public_id),
            (string) ($employee->name ?? $employee->employee_code ?? $employee->public_id),
            $this->challenges->issue($employee, DeviceChallenges::REGISTER),
            array_map('strval', $exclude),
        );
    }

    /**
     * Options for the browser to sign one punch with the employee's passkey.
     *
     * @return array<string, mixed>
     */
    public function authenticationOptions(Employee $employee): array
    {
        $active = $this->deviceFor($employee, AttendanceDevice::ACTIVE);

        return $this->passkeys->authenticationOptions(
            $this->rpId(),
            $this->challenges->issue($employee, DeviceChallenges::PUNCH),
            $active === null ? [] : [(string) $active->credential_id],
        );
    }

    /**
     * Register the passkey the browser created. Active at once only when made
     * at work by someone with no device yet; otherwise pending approval.
     *
     * @param  array<string, mixed>  $credential  RegistrationResponseJSON
     */
    public function register(Employee $employee, Request $request, array $credential, ?float $latitude, ?float $longitude, ?float $accuracy, ?string $installId, ?string $label): AttendanceDevice
    {
        $rpId = $this->rpId();
        $clientData = json_decode(PasskeyVerifier::decode((string) data_get($credential, 'response.clientDataJSON', '')), true);
        $challenge = PasskeyVerifier::decode((string) (is_array($clientData) ? ($clientData['challenge'] ?? '') : ''));

        if ($challenge === '' || ! $this->challenges->consume($employee, DeviceChallenges::REGISTER, $challenge)) {
            throw DeviceBindingRefused::from(new DeviceVerdict(DeviceVerdict::INVALID));
        }

        try {
            $verified = $this->passkeys->verifyRegistration($credential, $rpId, $challenge);
        } catch (PasskeyRejected) {
            throw DeviceBindingRefused::from(new DeviceVerdict(DeviceVerdict::INVALID));
        }

        $installHash = is_string($installId) && $installId !== '' ? PunchSignals::deviceHash($installId) : null;
        $cookieHash = $this->fingerprint->existingHash($request);

        // One phone, one person: this browser already punches for someone else.
        // A leaver's phone is revoked when they leave (employeeLeft); one left
        // over from before that never blocks the colleague it was handed to.
        $taken = AttendanceDevice::query()
            ->where('employee_id', '!=', $employee->id)
            ->where('status', AttendanceDevice::ACTIVE)
            ->whereHas('employee', fn ($query) => $query->whereNotIn('status', EmployeeStatus::leaving()))
            ->where(function ($query) use ($installHash, $cookieHash, $verified): void {
                $query->where('credential_id_hash', AttendanceDevice::hashCredential($verified['credential_id']));
                if ($installHash !== null) {
                    $query->orWhere('install_hash', $installHash);
                }
                if ($cookieHash !== null) {
                    $query->orWhere('cookie_hash', $cookieHash);
                }
            })
            ->exists();
        if ($taken) {
            AuditLog::record('attendance.device_refused', $employee, ['reason' => 'belongs_to_another']);
            throw DeviceBindingRefused::from(new DeviceVerdict(DeviceVerdict::FOREIGN));
        }

        $inside = $this->atWork($employee, $latitude, $longitude, $accuracy);
        $hasActive = $this->deviceFor($employee, AttendanceDevice::ACTIVE) !== null;
        $status = $inside && ! $hasActive ? AttendanceDevice::ACTIVE : AttendanceDevice::PENDING;

        $device = DB::transaction(function () use ($employee, $verified, $rpId, $installHash, $cookieHash, $label, $status, $inside): AttendanceDevice {
            // A newer request replaces an older one still waiting.
            AttendanceDevice::query()
                ->where('employee_id', $employee->id)
                ->where('status', AttendanceDevice::PENDING)
                ->update(['status' => AttendanceDevice::REJECTED, 'revoked_at' => Carbon::now('UTC')]);

            return AttendanceDevice::query()->create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'credential_id' => $verified['credential_id'],
                'credential_id_hash' => AttendanceDevice::hashCredential($verified['credential_id']),
                'public_key' => $verified['public_key'],
                'sign_count' => $verified['sign_count'],
                'rp_id' => $rpId,
                'aaguid' => $verified['aaguid'],
                'backup_eligible' => $verified['backup_eligible'],
                'backup_state' => $verified['backup_state'],
                'install_hash' => $installHash,
                'cookie_hash' => $cookieHash,
                'label' => $label === null ? null : mb_substr($label, 0, 120),
                'status' => $status,
                'enrolled_inside_geofence' => $inside,
            ]);
        });

        AuditLog::record($status === AttendanceDevice::ACTIVE ? 'attendance.device_registered' : 'attendance.device_requested', $device, [
            'employee' => $employee->public_id,
            'inside_geofence' => $inside,
        ]);

        if ($status === AttendanceDevice::PENDING) {
            $this->tellApprovers($device, $employee, $hasActive);
        }

        return $device;
    }

    /**
     * Activate a waiting device: the employee's other active device is
     * revoked in the same transaction, so there is never more than one.
     */
    public function approve(AttendanceDevice $device, int $approverId): AttendanceDevice
    {
        $replaced = DB::transaction(function () use ($device, $approverId): int {
            $replaced = AttendanceDevice::query()
                ->where('employee_id', $device->employee_id)
                ->where('status', AttendanceDevice::ACTIVE)
                ->whereKeyNot($device->getKey())
                ->update(['status' => AttendanceDevice::REVOKED, 'revoked_at' => Carbon::now('UTC')]);

            $device->forceFill([
                'status' => AttendanceDevice::ACTIVE,
                'approved_by' => $approverId,
                'approved_at' => Carbon::now('UTC'),
            ])->save();

            return $replaced;
        });

        AuditLog::record('attendance.device_approved', $device, ['replaced' => $replaced]);
        $this->tellEmployee($device, AttendanceDevice::ACTIVE, null);

        return $device;
    }

    public function reject(AttendanceDevice $device, ?string $reason = null): AttendanceDevice
    {
        $device->forceFill(['status' => AttendanceDevice::REJECTED, 'revoked_at' => Carbon::now('UTC')])->save();
        AuditLog::record('attendance.device_rejected', $device, ['reason' => $reason]);
        $this->tellEmployee($device, AttendanceDevice::REJECTED, $reason);

        return $device;
    }

    public function revoke(AttendanceDevice $device, ?string $reason = null): AttendanceDevice
    {
        $device->forceFill(['status' => AttendanceDevice::REVOKED, 'revoked_at' => Carbon::now('UTC')])->save();
        AuditLog::record('attendance.device_revoked', $device, ['reason' => $reason]);
        $this->tellEmployee($device, AttendanceDevice::REVOKED, $reason);

        return $device;
    }

    /**
     * Someone left: their phone stops punching and a request still waiting
     * is closed, so the phone can be handed to a colleague. Nobody is told;
     * the person has gone.
     */
    public function employeeLeft(Employee $employee): void
    {
        $now = Carbon::now('UTC');
        $revoked = AttendanceDevice::query()
            ->where('employee_id', $employee->id)
            ->where('status', AttendanceDevice::ACTIVE)
            ->update(['status' => AttendanceDevice::REVOKED, 'revoked_at' => $now]);
        $rejected = AttendanceDevice::query()
            ->where('employee_id', $employee->id)
            ->where('status', AttendanceDevice::PENDING)
            ->update(['status' => AttendanceDevice::REJECTED, 'revoked_at' => $now]);

        if ($revoked + $rejected > 0) {
            AuditLog::record('attendance.device_revoked', $employee, ['reason' => 'left', 'revoked' => $revoked, 'rejected' => $rejected]);
        }
    }

    /**
     * The people whose phone is checked today: they work here now, their
     * branch (or the organisation) checks phones and lets them punch from a
     * phone or browser at all, and HR has not exempted them.
     *
     * @return Builder<Employee>
     */
    public function employeesChecked(): Builder
    {
        $organisation = $this->policy->organisation();
        $orgChecks = $this->checksPhones($organisation);
        $exceptions = [];

        foreach (BranchAttendanceOverride::query()->pluck('branch_id') as $branchId) {
            if ($this->checksPhones($this->policy->forBranch((int) $branchId, $organisation)) !== $orgChecks) {
                $exceptions[] = (int) $branchId;
            }
        }

        $today = Carbon::now('UTC')->format('Y-m-d');

        return Employee::query()
            ->whereIn('status', EmployeeStatus::current())
            ->where(fn ($query) => $query
                ->whereNull('attendance_device_exempt_until')
                ->orWhereDate('attendance_device_exempt_until', '<', $today))
            ->where(function ($query) use ($orgChecks, $exceptions): void {
                if ($orgChecks) {
                    $query->whereNull('branch_id')->orWhereNotIn('branch_id', $exceptions === [] ? [0] : $exceptions);
                } else {
                    $query->whereIn('branch_id', $exceptions === [] ? [0] : $exceptions);
                }
            });
    }

    /** Whether these rules check phones: binding on and a way to punch from a phone or browser. */
    private function checksPhones(AttendanceSetting $policy): bool
    {
        $binding = (string) ($policy->device_binding ?? 'off');
        if (! in_array($binding, ['flag', 'enforce'], true)) {
            return false;
        }

        foreach (['mobile', 'web', 'qr'] as $method) {
            if ($policy->isMethodEnabled($method)) {
                return true;
            }
        }

        return false;
    }

    /** The people who approve phones hear that one is waiting. */
    private function tellApprovers(AttendanceDevice $device, Employee $employee, bool $replacing): void
    {
        try {
            User::query()
                ->where('tenant_id', $employee->tenant_id)
                ->whereIn('role', [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value])
                ->where('status', 'active')
                ->get()
                ->each(fn (User $approver) => $approver->notify(new AttendanceDeviceRequestedNotification($device, (string) $employee->name, $replacing)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The employee hears what became of their phone. */
    private function tellEmployee(AttendanceDevice $device, string $outcome, ?string $reason): void
    {
        try {
            User::query()
                ->where('tenant_id', $device->tenant_id)
                ->where('employee_id', $device->employee_id)
                ->first()
                ?->notify(new AttendanceDeviceDecidedNotification($device, $outcome, $reason));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The verdict for a punch made offline and signed on the phone over its
     * own fields (OfflineGrant::proofChallenge), checked when it syncs.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>|null  $grant  the grant's decrypted payload
     */
    public function offlineVerdict(Employee $employee, array $record, ?array $grant, string $challenge): DeviceVerdict
    {
        $policy = $this->policy->for($employee);
        $binding = $this->binding($employee, $policy);
        if ($binding === 'off') {
            return new DeviceVerdict(DeviceVerdict::OFF, $binding);
        }

        $proof = is_array($record['device_proof'] ?? null) ? $record['device_proof'] : null;
        $device = $proof === null ? null : $this->lookup($proof);
        $grantCredential = is_array($grant) ? ($grant['c'] ?? null) : null;

        if ($device !== null && $device->employee_id !== $employee->id) {
            return new DeviceVerdict(DeviceVerdict::FOREIGN, $binding);
        }

        if ($device !== null && $device->status === AttendanceDevice::ACTIVE
            && $grantCredential === $device->credential_id_hash
            && $this->signatureValid($device, $proof, $challenge)) {
            return new DeviceVerdict(DeviceVerdict::VERIFIED, $binding, $device, $this->installChanged($device, $record['device_id'] ?? null));
        }

        if ($this->exempt($employee)) {
            return new DeviceVerdict(DeviceVerdict::EXEMPT, $binding);
        }

        if ($proof !== null) {
            return new DeviceVerdict(DeviceVerdict::INVALID, $binding, $device);
        }

        return new DeviceVerdict($this->deviceFor($employee, AttendanceDevice::ACTIVE) !== null
            ? DeviceVerdict::INVALID
            : DeviceVerdict::UNREGISTERED, $binding);
    }

    /**
     * Look up and check an online proof: the passkey must be the employee's
     * own and active, the challenge theirs and unspent, the signature good.
     *
     * @param  array<string, mixed>  $proof
     */
    private function verifyProof(Employee $employee, array $proof, string $rpId, Request $request): DeviceVerdict
    {
        $binding = $this->binding($employee);
        $device = $this->lookup($proof);

        if ($device === null) {
            return new DeviceVerdict(DeviceVerdict::INVALID, $binding);
        }
        if ($device->employee_id !== $employee->id) {
            AuditLog::record('attendance.device_refused', $employee, ['reason' => 'belongs_to_another']);

            return new DeviceVerdict(DeviceVerdict::FOREIGN, $binding);
        }
        if ($device->status === AttendanceDevice::PENDING) {
            return new DeviceVerdict(DeviceVerdict::PENDING, $binding, $device);
        }
        if ($device->status !== AttendanceDevice::ACTIVE || $device->rp_id !== $rpId) {
            return new DeviceVerdict(DeviceVerdict::INVALID, $binding, $device);
        }

        $challenge = $this->passkeys->claimedChallenge($proof);
        if ($challenge === null || ! $this->challenges->consume($employee, DeviceChallenges::PUNCH, $challenge)) {
            return new DeviceVerdict(DeviceVerdict::INVALID, $binding, $device);
        }
        if (! $this->signatureValid($device, $proof, $challenge)) {
            return new DeviceVerdict(DeviceVerdict::INVALID, $binding, $device);
        }

        $installId = $request->input('device_id');

        return new DeviceVerdict(DeviceVerdict::VERIFIED, $binding, $device, $this->installChanged($device, is_string($installId) ? $installId : null));
    }

    /** @param  array<string, mixed>  $proof */
    private function lookup(array $proof): ?AttendanceDevice
    {
        $id = (string) ($proof['id'] ?? '');

        return $id === '' ? null : AttendanceDevice::query()
            ->where('credential_id_hash', AttendanceDevice::hashCredential($id))
            ->first();
    }

    /** @param  array<string, mixed>  $proof */
    private function signatureValid(AttendanceDevice $device, array $proof, string $challenge): bool
    {
        try {
            $result = $this->passkeys->verifyAssertion($proof, (string) $device->rp_id, (string) $device->public_key, (int) $device->sign_count, $challenge);
        } catch (PasskeyRejected) {
            return false;
        }

        $device->forceFill([
            'sign_count' => max((int) $device->sign_count, $result['sign_count']),
            'backup_state' => $result['backup_state'],
            'last_used_at' => Carbon::now('UTC'),
        ])->save();

        return true;
    }

    /** The passkey signed from a different install than it was registered on (Safari clears storage after a week unused, so this is a signal, not a refusal). */
    private function installChanged(AttendanceDevice $device, ?string $installId): bool
    {
        return $device->install_hash !== null
            && is_string($installId) && $installId !== ''
            && PunchSignals::deviceHash($installId) !== $device->install_hash;
    }

    private function exempt(Employee $employee): bool
    {
        $until = $employee->attendance_device_exempt_until;

        return $until !== null && Carbon::parse($until)->endOfDay()->isFuture();
    }

    private function onOfficeNetwork(Request $request, AttendanceSetting $policy): bool
    {
        $networks = array_filter((array) ($policy->office_networks ?? []), 'is_string');
        $ip = $request->ip();

        return $networks !== [] && is_string($ip) && IpUtils::checkIp($ip, $networks);
    }

    /** Registration at work: inside the branch or a site, with a fix good enough to say so. Remote workers never are. */
    private function atWork(Employee $employee, ?float $latitude, ?float $longitude, ?float $accuracy): bool
    {
        if ($latitude === null || $longitude === null || AttendancePolicy::modeOf($employee) === AttendanceMode::REMOTE) {
            return false;
        }

        $threshold = (int) ($this->policy->for($employee)->mobile_accuracy_threshold_meters ?? 100);
        if ($accuracy !== null && $accuracy > $threshold) {
            return false;
        }

        return $this->places->check($this->places->for($employee), $latitude, $longitude, $accuracy)['inside'] === true;
    }

    /** The organisation's own address for the punch screen, where its passkeys work. */
    public function canonicalUrl(Employee $employee): ?string
    {
        $tenant = Tenant::query()->find($employee->tenant_id);

        return $tenant === null ? null : FrontendUrl::at($tenant, '/attendance/mobile');
    }
}
