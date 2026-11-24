<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Enums\EmployeeStatus;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Holiday\HolidayService;
use App\Support\DeviceSilenceRules;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Which terminals that only send to ETHR have gone quiet when they should not
 * have, for the organisation in hand. One reader for the scheduled sweep
 * (`devices:watch-silence`, which tells the admins) and the health dashboard
 * (which shows the same list), so the page and the notice cannot disagree.
 *
 * ETHR cannot call such a terminal, so silence is read from what it sent, and
 * the two kinds of terminal give different evidence:
 *
 *  - **ADMS** pings all the time and every ping stamps `last_sync_at`. Not
 *    heard for `adms_minutes` of working time is a real fault: «not
 *    connected». The clock starts at the later of the last ping and the
 *    start of today's working hours, so a terminal switched off overnight is
 *    reported half an hour into the day, not at the first minute of it.
 *  - **Hikvision push, and ZKTeco or Suprema registered with no address** are
 *    heard on a punch. A Hikvision heartbeat also stamps `last_sync_at`, so
 *    that column is not punch evidence: the inbox rows are. No punch since
 *    the start of the day, `push_grace_minutes` after the working day began,
 *    while the branch has people who could have punched: «no punches today».
 *    The organisation may simply have been closed, which is why this one is
 *    a question about the people, not the terminal.
 *
 * Pulled terminals are not here: `PullDeviceEventsJob` already notifies for
 * them. A terminal never heard from is not silent, it is not set up yet
 * (`AttendanceHealth` reports that), and one registered after the day began
 * has had no chance to be heard.
 *
 * Working hours are the default shift's: its days, start and end, minus the
 * holidays that apply to the terminal's branch. An organisation with no
 * active default shift (crews on a default rotation) has no hours to measure
 * against and gets no notice. A shift that crosses midnight is measured from
 * the day it starts.
 *
 * The caller has the tenant set (`CurrentTenant`) and every query here runs
 * through the tenant scope, so no scope is dropped. Asked for a tenant other
 * than the resolved one, it answers nothing.
 */
final class DeviceSilence
{
    public const ADMS = 'adms';

    public const PUSH = 'push';

    /** How long before a shift that crosses midnight a punch still counts as that shift's. */
    private const EARLY_ARRIVAL_HOURS = 2;

    /** The adapters that can be a terminal that only sends; `receivesOnly` decides which are. */
    private const SENDING_ADAPTERS = ['hikvision', 'zkteco', 'suprema', AdmsDeviceResolver::ADAPTER];

    /** @var array<int|string, bool> */
    private array $badgeHolders = [];

    public function __construct(private readonly HolidayService $holidays) {}

    /**
     * `date` is the day the working window began, which a notice is counted
     * against once a day.
     *
     * @return list<array{device: Device, kind: self::ADMS|self::PUSH, since: CarbonImmutable, date: string}>
     */
    public function silentDevices(Tenant $tenant, ?CarbonImmutable $now = null): array
    {
        if (app(CurrentTenant::class)->id() !== $tenant->id) {
            return [];
        }

        $rules = DeviceSilenceRules::forTenant($tenant);
        if (! $rules->enabled) {
            return [];
        }

        $now ??= CarbonImmutable::instance(now());
        $zone = TenantTime::zone($tenant);

        $shift = Shift::query()->where('is_default', true)->where('is_active', true)->first();
        if ($shift === null) {
            return [];
        }

        $window = $this->window($shift, $now, $zone);
        if ($window === null) {
            return [];
        }

        [$date, $start, $end] = $window;
        // Where a punch counts as today's: the start of the organisation's day,
        // or for a shift that crosses midnight a little before it begins, since
        // the last shift's check-outs arrive after midnight and are not this
        // one's evidence.
        $crosses = $end->setTimezone($zone)->toDateString() !== $date;
        $evidenceFrom = $crosses
            ? $start->subHours(self::EARLY_ARRIVAL_HOURS)
            : CarbonImmutable::parse($date, $zone)->startOfDay()->utc();

        $devices = Device::query()
            ->with('branch')
            ->whereIn('adapter_type', self::SENDING_ADAPTERS)
            ->get()
            ->filter(fn (Device $device): bool => DeviceManager::receivesOnly($device));

        $silent = [];
        $this->badgeHolders = [];

        foreach ($devices as $device) {
            if ($this->isHoliday($tenant, $device, $date)) {
                continue;
            }

            $found = $device->adapter_type === AdmsDeviceResolver::ADAPTER
                ? $this->admsSilence($device, $rules, $now, $start)
                : $this->pushSilence($device, $rules, $now, $start, $evidenceFrom);

            if ($found !== null) {
                $silent[] = ['device' => $device, 'kind' => $found['kind'], 'since' => $found['since'], 'date' => $date];
            }
        }

        return $silent;
    }

    /**
     * The working window the moment falls in: the date it belongs to and its
     * start and end, as instants. Today's, or yesterday's when a shift that
     * crosses midnight is still running. Null on a rest day or outside the
     * hours.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}|null
     */
    private function window(Shift $shift, CarbonImmutable $now, string $zone): ?array
    {
        $local = $now->setTimezone($zone);

        foreach ([0, 1] as $daysBack) {
            $day = $local->subDays($daysBack)->startOfDay();

            if (! $shift->isWorkingDay($day)) {
                continue;
            }

            $date = $day->toDateString();
            $start = TenantTime::wallClockToUtc($date, (string) $shift->start_time, $zone);
            $end = TenantTime::wallClockToUtc($date, (string) $shift->end_time, $zone);

            if ($shift->crosses_midnight || $end->lessThanOrEqualTo($start)) {
                $end = TenantTime::wallClockToUtc($day->addDay()->toDateString(), (string) $shift->end_time, $zone);
            }

            if ($now->greaterThanOrEqualTo($start) && $now->lessThanOrEqualTo($end)) {
                return [$date, $start, $end];
            }
        }

        return null;
    }

    private function isHoliday(Tenant $tenant, Device $device, string $date): bool
    {
        $day = Carbon::parse($date);

        return isset($this->holidays->getHolidayDates($tenant->id, $day, $day, $device->branch_id)[$date]);
    }

    /** @return array{kind: self::ADMS, since: CarbonImmutable}|null */
    private function admsSilence(Device $device, DeviceSilenceRules $rules, CarbonImmutable $now, CarbonImmutable $start): ?array
    {
        if ($device->last_sync_at === null) {
            return null;
        }

        $heard = CarbonImmutable::instance($device->last_sync_at);
        $measuredFrom = $heard->greaterThan($start) ? $heard : $start;

        if ($measuredFrom->diffInSeconds($now, false) < $rules->admsMinutes * 60) {
            return null;
        }

        return ['kind' => self::ADMS, 'since' => $heard];
    }

    /** @return array{kind: self::PUSH, since: CarbonImmutable}|null */
    private function pushSilence(Device $device, DeviceSilenceRules $rules, CarbonImmutable $now, CarbonImmutable $start, CarbonImmutable $evidenceFrom): ?array
    {
        if ($device->last_sync_at === null || $start->diffInSeconds($now, false) < $rules->pushGraceMinutes * 60) {
            return null;
        }

        if ($device->created_at === null || $device->created_at->greaterThanOrEqualTo($start)) {
            return null;
        }

        $punched = DeviceInboundPunch::query()
            ->where('device_id', $device->id)
            ->where('created_at', '>=', $evidenceFrom)
            ->exists();

        if ($punched || ! $this->branchHasBadgeHolders($device->branch_id)) {
            return null;
        }

        return ['kind' => self::PUSH, 'since' => $start];
    }

    /**
     * Whether anyone at the terminal's branch could punch on it: an active
     * employee with a badge number. A terminal with no branch serves the
     * whole organisation.
     */
    private function branchHasBadgeHolders(?int $branchId): bool
    {
        return $this->badgeHolders[$branchId ?? 'all'] ??= Employee::query()
            ->whereNotNull('badge_number')
            ->where('badge_number', '!=', '')
            ->whereIn('status', EmployeeStatus::current())
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->exists();
    }
}
