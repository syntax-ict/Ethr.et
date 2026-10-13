<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Support\TenantTime;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use App\Traits\NeverDelete;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property AttendanceSource $source
 * @property AttendanceStatus $status
 * @property Carbon|null $check_in
 * @property Carbon|null $check_out
 * @property Carbon|null $date
 * @property array<string, mixed>|null $metadata
 */
class AttendanceRecord extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, NeverDelete;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'shift_id',
        'date',
        'check_in',
        'check_out',
        'source',
        'confidence_score',
        'latitude',
        'longitude',
        'geofence_verified',
        'photo_path',
        'device_id',
        'status',
        'offline_token',
        'idempotency_key',
        'metadata',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'shift_id',
        'device_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => AttendanceSource::class,
            'status' => AttendanceStatus::class,
            'date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'confidence_score' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_verified' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function workedMinutes(): int
    {
        if (! $this->check_in || ! $this->check_out) {
            return 0;
        }

        $total = $this->check_in->diffInMinutes($this->check_out);
        $breakMinutes = $this->shift?->break_minutes ?? 0;

        return max(0, (int) $total - $breakMinutes);
    }

    public function overtimeMinutes(): int
    {
        $window = $this->overtimeWindow();

        return $window === null ? 0 : (int) $window[0]->diffInMinutes($window[1]);
    }

    /**
     * The worked-past-shift-end interval that counts as overtime, or null when
     * there is none. Single source of truth for both the total OT minutes and
     * the per-type (day/night, holiday) classification done in payroll.
     *
     * @return array{0: Carbon, 1: Carbon}|null [start, end]
     */
    /**
     * The whole span worked, for a day with no scheduled hours (a weekly rest
     * day or a public holiday), where all of it is paid as overtime.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function workedWindow(): ?array
    {
        if (! $this->check_in || ! $this->check_out || $this->check_out->lte($this->check_in)) {
            return null;
        }

        $zone = $this->tenantZone();

        return [$this->check_in->copy()->setTimezone($zone), $this->check_out->copy()->setTimezone($zone)];
    }

    public function overtimeWindow(): ?array
    {
        // The punches first: an open record has no overtime, and asking for
        // its shift first loaded one per row. The intelligence screen lists
        // open records (missing punches) without the shift, so with any open
        // check-in it answered 500 under strict loading and ran a query per
        // row in production.
        if (! $this->check_in || ! $this->check_out || ! $this->shift) {
            return null;
        }

        // Shift times are wall-clock times in the tenant's zone. On the UTC
        // clock a 23:30 Addis shift end was 02:30 the next morning, so
        // overtime almost never started; and payroll splits the window into
        // day and night hours by its clock, so the window is returned in the
        // tenant's zone too (attendance QA A1).
        $zone = $this->tenantZone();
        $checkIn = $this->check_in->copy()->setTimezone($zone);
        $shiftEnd = $checkIn->copy()->setTimeFromTimeString($this->shift->end_time);

        if ($this->shift->crosses_midnight && $shiftEnd->lte($checkIn)) {
            $shiftEnd->addDay();
        }

        if ($this->check_out->gt($shiftEnd)) {
            return [$shiftEnd, $this->check_out->copy()->setTimezone($zone)];
        }

        return null;
    }

    /** @var array<int, string> tenant id => zone, for the life of the request */
    private static array $zones = [];

    /**
     * The owning tenant's zone. Cached per tenant: payroll asks every record
     * of a period, and the tenant is the same for all of them.
     */
    private function tenantZone(): string
    {
        // Read raw: the property is typed as an int, but a record built in
        // memory (the classifier's unit tests) has no tenant at all.
        $tenantId = $this->getAttribute('tenant_id');

        if (! is_int($tenantId)) {
            return TenantTime::DEFAULT_ZONE;
        }

        return self::$zones[$tenantId] ??= TenantTime::zone(Tenant::query()->find($tenantId));
    }
}
