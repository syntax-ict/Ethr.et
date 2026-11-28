<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Exceptions\AttendanceRefused;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\UnmatchedDevicePunch;
use App\Models\User;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Punches from a badge no employee matched: held, then replayed once someone
 * says whose badge it is, or dismissed (blueprint assessment AT3, AT20).
 *
 * Every device path used to log the badge and drop the punch. Held punches
 * keep the idempotency key they would have been recorded under, so a replay,
 * a re-pull or a redelivered webhook can never record one twice.
 */
final class UnmatchedPunches
{
    /** How long one request replays before it answers, the same twelve seconds as an import slice. */
    public const SLICE_SECONDS = 12.0;

    /** Longest the lock outlives a request that died; far over a slice and one punch. */
    private const LOCK_SECONDS = 60;

    /** Punches read at a time, so a badge with thousands is never loaded whole. */
    private const CHUNK = 50;

    public function __construct(
        private readonly IdentityResolver $resolver,
        private readonly TerminalNames $terminalNames,
        private readonly float $sliceSeconds = self::SLICE_SECONDS,
    ) {}

    /**
     * Keep a punch whose badge matched no employee. A second delivery of the
     * same punch is already held and is ignored.
     */
    public function hold(Device $device, string $badge, string $type, string $idempotencyKey, string $occurredRaw): void
    {
        try {
            UnmatchedDevicePunch::create([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'badge' => mb_substr($badge, 0, 64),
                'punch_type' => mb_substr($type, 0, 20),
                'occurred_raw' => mb_substr($occurredRaw, 0, 64),
                'idempotency_key' => mb_substr($idempotencyKey, 0, 191),
                'status' => UnmatchedDevicePunch::PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already held.
        }
    }

    /**
     * Say whose badge it is: remember the mapping, verified, so the device's
     * next punches resolve at once, and replay the held punches through the
     * normal device path, earliest first.
     *
     * The host allows a request about thirty seconds, and a badge can hold
     * hundreds of punches, so the replay works for `sliceSeconds` and says how
     * many are left. It always takes at least one when any is pending, so every
     * call makes progress; asking again with the same three inputs carries on
     * where it stopped, and a punch already replayed or refused is not looked
     * at again. One lock per organisation, device and badge keeps two requests
     * from replaying the same punch; a caller that cannot take it is told to
     * try again (409), and nothing has been linked or replayed for it.
     *
     * Each call that replays or refuses a punch writes one audit entry with
     * its own counts: `device.badge_assigned` when it made or changed the
     * link, `device.held_punches_replayed` otherwise. A call that finds
     * nothing pending writes none.
     *
     * `$acceptedWarnings` are the codes the server itself found and the person
     * acknowledged; they are written to the audit entry so the log shows a
     * person assigned despite them. Automatic recording never comes here.
     *
     * @param  list<string>  $acceptedWarnings
     * @return array{replayed: int, refused: int, remaining: int}
     */
    public function assign(Device $device, string $badge, Employee $employee, User $by, DevicePunchRecorder $recorder, array $acceptedWarnings = []): array
    {
        $lock = Cache::lock(self::lockKey($device, $badge), self::LOCK_SECONDS);

        if (! $lock->get()) {
            abort(409, __('device.held_busy'));
        }

        try {
            $signals = IdentitySignals::forDeviceUser($device, $badge);
            $alreadyTheirs = $this->confirmedFor($device, $signals, $employee);

            // A person is saying whose number this is, so it moves even from
            // someone it was confirmed for; the resolver records who held it.
            $this->resolver->link($employee, $signals, 1.0, verified: true, reassign: true);

            [$replayed, $refused] = $this->replay($device, $badge, $employee, $by, $recorder);

            // Punches enter attendance at a person's click, so every call that
            // replays or refuses one leaves an entry with its own counts. It is
            // `device.badge_assigned` when this call made or changed the link,
            // and `device.held_punches_replayed` when it only carried on, or
            // the number was already confirmed (an import does that).
            if ($replayed + $refused > 0) {
                AuditLog::record($alreadyTheirs ? 'device.held_punches_replayed' : 'device.badge_assigned', $device, [
                    'badge' => $badge,
                    'employee_id' => $employee->public_id,
                    'replayed' => $replayed,
                    'refused' => $refused,
                    'warnings' => $acceptedWarnings,
                ]);
            }

            return ['replayed' => $replayed, 'refused' => $refused, 'remaining' => $this->pending($device, $badge)->count()];
        } finally {
            $lock->release();
        }
    }

    /**
     * Whose a held badge is, as the resolver sees it today, with what the
     * terminal calls it. The one lookup the Unrecognised badges card and the
     * automatic recording both use, so what the card says is what happens.
     */
    public function verdict(Device $device, string $badge, ?string $nameOnDevice): IdentityMatch
    {
        return $this->resolver->resolve($device->tenant_id, IdentitySignals::forDeviceUser($device, $badge, null, $nameOnDevice));
    }

    /**
     * Whether a badge's held punches record themselves, with no one's click.
     * All of these must hold, and a failure at any leaves them held:
     *
     * a. The verdict is an actionable match to a person who is still here
     *    (not a leaver, not archived). A name alone never is.
     * b. No person has replayed any punch of this badge on this device: no row
     *    for this organisation, device and badge that is `replayed` or
     *    `refused` with a `resolved_by`. A person who started a replay and
     *    stopped may have stopped because the result looked wrong, so what is
     *    left is theirs to finish. Dismissed rows do not count, and the
     *    automatic path leaves `resolved_by` empty, so its own slices never
     *    block its next one.
     * c. The number says so today, with any mapping set aside. The resolver
     *    returns a match for an existing mapping with no scoring at all, so a
     *    mapping alone proves only that someone once linked it. The number
     *    and the terminal's name are scored again without it: the result must
     *    be an actionable match to the same person. A mapping a person
     *    confirmed also passes when the scored result names no other current
     *    employee (new, or only an archived holder): that is how terminal
     *    users are mapped when nobody holds the number. Either way the number
     *    is held by exactly one current employee, whatever the name adds to
     *    the score.
     *
     * d. The terminal's own name points to the same person and nobody else:
     *    it sent a name for this badge, and `TerminalNames::lookalikes()`
     *    returns exactly one employee, the verdict's. Two independent things,
     *    the number and the name, must name the same single person, because a
     *    mistyped number on one employee would otherwise record on the wrong
     *    person within minutes, with no undo. A terminal that sends no name, a
     *    one-word name, a name two people fit (duplicate records included) or
     *    a name that fits someone else leaves the punches for a person's
     *    click. It applies to every automatic path, a mapping a person
     *    confirmed included, and is evaluated last so ordinary rows cost
     *    nothing more.
     *
     * The second resolve runs only when the verdict came from a mapping;
     * otherwise the verdict is the scored result.
     */
    public function recordsItself(Device $device, string $badge, ?string $nameOnDevice, IdentityMatch $verdict): bool
    {
        if (
            ! $verdict->isActionable()
            || $verdict->isFormerEmployee()
            || $verdict->employee === null
            || $verdict->employee->trashed()
        ) {
            return false;
        }

        $employee = $verdict->employee;

        if ($this->aPersonStartedReplaying($device, $badge)) {
            return false;
        }

        if (! in_array('existing_mapping', $verdict->candidates[0]['reasons'] ?? [], true)) {
            return $this->soleHolder($verdict) && $this->nameAgrees($device, $nameOnDevice, $employee);
        }

        $scored = $this->resolver->resolve($device->tenant_id, new IdentitySignals(
            employeeCode: $badge,
            badgeNumber: $badge,
            name: $nameOnDevice,
            codeIsGuess: true,
        ));

        $numberAgrees = ($scored->isActionable() && $scored->employee?->id === $employee->id && $this->soleHolder($scored))
            || (in_array($scored->outcome, [IdentityMatch::NEW, IdentityMatch::ARCHIVED], true)
                && $this->confirmedFor($device, IdentitySignals::forDeviceUser($device, $badge), $employee));

        return $numberAgrees && $this->nameAgrees($device, $nameOnDevice, $employee);
    }

    /**
     * Exactly one scored candidate holds the number, as employee code or badge
     * number. A leaver who holds it counts, the conservative side.
     */
    private function soleHolder(IdentityMatch $match): bool
    {
        $holders = 0;

        foreach ($match->candidates as $candidate) {
            $reasons = $candidate['reasons'];

            if (in_array('employee_code', $reasons, true) || in_array('badge_number', $reasons, true)) {
                $holders++;
            }
        }

        return $holders === 1;
    }

    /** Condition d: the terminal's name fits this employee and nobody else. */
    private function nameAgrees(Device $device, ?string $nameOnDevice, Employee $employee): bool
    {
        if ($nameOnDevice === null || trim($nameOnDevice) === '') {
            return false;
        }

        $fits = $this->terminalNames->lookalikes($device->tenant_id, $nameOnDevice);

        return count($fits) === 1 && $fits[0]->id === $employee->id;
    }

    /** Whether a person replayed any punch of this badge on this device, whatever is left. */
    private function aPersonStartedReplaying(Device $device, string $badge): bool
    {
        return UnmatchedDevicePunch::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->where('badge', $badge)
            ->whereIn('status', [UnmatchedDevicePunch::REPLAYED, UnmatchedDevicePunch::REFUSED])
            ->whereNotNull('resolved_by')
            ->exists();
    }

    /**
     * Record the held punches of a badge whose number now names one current
     * employee, with no one's click: the same replay as `assign()`, earliest
     * first, in one slice. The link carries the match's own confidence, not
     * verified, and never moves a number a person confirmed for someone else.
     *
     * Returns null when there was nothing to do: the verdict is not one to act
     * on, or a manual assign (or another run) holds the badge's lock, which is
     * not an error, the next run carries on. Otherwise the slice's counts.
     * Each call that replays or refuses a punch writes
     * `device.held_punches_auto_recorded`.
     *
     * @return array{replayed: int, refused: int, remaining: int}|null
     */
    public function recordMatched(Device $device, string $badge, DevicePunchRecorder $recorder): ?array
    {
        $lock = Cache::lock(self::lockKey($device, $badge), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $name = $this->terminalNames->forBadges($device->tenant_id, [[$device->id, $badge]])[$device->id][mb_strtolower($badge)] ?? null;
            $match = $this->verdict($device, $badge, $name === null ? null : (string) $name);

            if (! $this->recordsItself($device, $badge, $name === null ? null : (string) $name, $match) || $match->employee === null) {
                return null;
            }

            $employee = $match->employee;
            $this->resolver->link($employee, IdentitySignals::forDeviceUser($device, $badge), $match->confidence);

            [$replayed, $refused] = $this->replay($device, $badge, $employee, null, $recorder);

            if ($replayed + $refused > 0) {
                AuditLog::record('device.held_punches_auto_recorded', $device, [
                    'badge' => $badge,
                    'employee_id' => $employee->public_id,
                    'reasons' => $match->candidates[0]['reasons'] ?? [],
                    'replayed' => $replayed,
                    'refused' => $refused,
                ]);
            }

            return ['replayed' => $replayed, 'refused' => $refused, 'remaining' => $this->pending($device, $badge)->count()];
        } finally {
            $lock->release();
        }
    }

    /**
     * Held badges that carry the evidence for automatic recording: a current
     * employee holds the number as badge number or employee code, or a mapping
     * for it exists, and the terminal has stored a name for the badge (a badge
     * with none can never record itself). A cheap filter so a platform sweep spends its bound on
     * badges that may match; the verdict itself is `recordMatched()`'s. It
     * reads across organisations, each row carrying its own `tenant_id`. The
     * bound is filled in random order, so badges that never act (a name that
     * differs) cannot crowd out the rest for good.
     *
     * @return list<array{tenant_id: int, device_id: int, badge: string}>
     */
    public static function sweepCandidates(int $limit = 200): array
    {
        $concat = DB::connection()->getDriverName() === 'sqlite' ? "'device:' || g.device_id" : "CONCAT('device:', g.device_id)";

        // One row per held badge first (the `unmatched_punch_lookup` index
        // covers it), then the evidence, so each test runs per badge and not
        // per held punch. A badge a person has started replaying is left out:
        // `recordsItself()` would refuse it anyway.
        $groups = DB::table('unmatched_device_punches')
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->groupBy('tenant_id', 'device_id', 'badge')
            ->select(['tenant_id', 'device_id', 'badge']);

        return DB::query()->fromSub($groups, 'g')
            ->where(function ($q) use ($concat): void {
                $q->whereExists(fn ($e) => $e->selectRaw('1')->from('employees as e')
                    ->whereColumn('e.tenant_id', 'g.tenant_id')
                    ->whereNull('e.deleted_at')
                    ->where(fn ($n) => $n->whereRaw('e.employee_code_normalized = LOWER(g.badge)')
                        ->orWhereRaw('LOWER(e.badge_number) = LOWER(g.badge)')))
                    ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('employee_external_identities as i')
                        ->whereColumn('i.tenant_id', 'g.tenant_id')
                        ->where('i.identifier_type', 'device_user_id')
                        ->whereColumn('i.identifier_value', 'g.badge')
                        ->whereRaw('i.source_ref = '.$concat));
            })
            ->whereExists(fn ($t) => $t->selectRaw('1')->from('device_enrollment_snapshots as s')
                ->whereColumn('s.tenant_id', 'g.tenant_id')
                ->whereColumn('s.device_id', 'g.device_id')
                ->whereRaw('LOWER(s.device_user_id) = LOWER(g.badge)')
                ->whereNotNull('s.name'))
            ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('unmatched_device_punches as r')
                ->whereColumn('r.tenant_id', 'g.tenant_id')
                ->whereColumn('r.device_id', 'g.device_id')
                ->whereColumn('r.badge', 'g.badge')
                ->whereIn('r.status', [UnmatchedDevicePunch::REPLAYED, UnmatchedDevicePunch::REFUSED])
                ->whereNotNull('r.resolved_by'))
            ->inRandomOrder()
            ->limit($limit)
            ->get(['g.tenant_id', 'g.device_id', 'g.badge'])
            ->map(fn ($row) => [
                'tenant_id' => (int) $row->tenant_id,
                'device_id' => (int) $row->device_id,
                'badge' => (string) $row->badge,
            ])
            ->values()
            ->all();
    }

    /** What keeps two requests off the same badge of the same device. */
    public static function lockKey(Device $device, string $badge): string
    {
        return 'held-punches:'.$device->tenant_id.':'.$device->id.':'.md5(mb_strtolower(trim($badge)));
    }

    /**
     * Whether this person already holds this device's number, confirmed, as
     * the call starts: then there is nothing to say about the link again.
     */
    private function confirmedFor(Device $device, IdentitySignals $signals, Employee $employee): bool
    {
        return EmployeeExternalIdentity::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('source_type', $signals->sourceType)
            ->where('source_ref', $signals->sourceRef ?? '')
            ->where('identifier_type', $signals->identifierType)
            ->where('identifier_value', $signals->identifierValue)
            ->where('employee_id', $employee->id)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * Replay pending punches, earliest first, until the time is spent. The
     * first is always taken. Each is marked as it is handled, so a request
     * that dies leaves the rest pending.
     *
     * @return array{0: int, 1: int} replayed and refused
     */
    private function replay(Device $device, string $badge, Employee $employee, ?User $by, DevicePunchRecorder $recorder): array
    {
        $deadline = microtime(true) + $this->sliceSeconds;
        $replayed = 0;
        $refused = 0;

        while (true) {
            $punches = $this->pending($device, $badge)->limit(self::CHUNK)->get();

            if ($punches->isEmpty()) {
                break;
            }

            foreach ($punches as $punch) {
                if ($replayed + $refused > 0 && microtime(true) >= $deadline) {
                    break 2;
                }

                $status = UnmatchedDevicePunch::REPLAYED;
                $refusal = null;

                try {
                    $recorder->record($device, $employee, $punch->punch_type, $punch->idempotency_key, $punch->occurred_raw);
                    $replayed++;
                } catch (AttendanceRefused $e) {
                    $status = UnmatchedDevicePunch::REFUSED;
                    $refusal = mb_substr($e->getMessage(), 0, 255);
                    $refused++;
                }

                $punch->update([
                    'status' => $status,
                    'refusal' => $refusal,
                    'employee_id' => $employee->id,
                    'resolved_by' => $by?->id,
                    'resolved_at' => now(),
                ]);
            }
        }

        return [$replayed, $refused];
    }

    /** Drop the held punches of a badge that is no one's. */
    public function dismiss(Device $device, string $badge, User $by): int
    {
        $count = $this->pending($device, $badge)->count();

        UnmatchedDevicePunch::query()
            ->where('device_id', $device->id)
            ->where('badge', $badge)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->update(['status' => UnmatchedDevicePunch::DISMISSED, 'resolved_by' => $by->id, 'resolved_at' => now()]);

        AuditLog::record('device.badge_dismissed', $device, ['badge' => $badge, 'punches' => $count]);

        return $count;
    }

    /**
     * Pending punches grouped by device and badge, most recent first.
     *
     * @return list<array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}>
     */
    public function groups(): array
    {
        return UnmatchedDevicePunch::query()
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->select([
                'device_id',
                'badge',
                DB::raw('COUNT(*) as punches'),
                DB::raw('MIN(created_at) as first_seen'),
                DB::raw('MAX(created_at) as last_seen'),
            ])
            ->groupBy('device_id', 'badge')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'device_id' => (int) $row->getAttribute('device_id'),
                'badge' => (string) $row->getAttribute('badge'),
                'punches' => (int) $row->getAttribute('punches'),
                'first_seen' => (string) $row->getAttribute('first_seen'),
                'last_seen' => (string) $row->getAttribute('last_seen'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<UnmatchedDevicePunch>
     */
    private function pending(Device $device, string $badge): Builder
    {
        return UnmatchedDevicePunch::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->where('badge', $badge)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->orderBy('id');
    }
}
