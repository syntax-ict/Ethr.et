<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'subdomain',
        'custom_domain',
        'type',
        'status',
        'logo_path',
        'theme',
        'default_locale',
        'timezone',
        'ethiopian_calendar',
        'settings',
        'trial_ends_at',
    ];

    protected $hidden = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'theme' => 'array',
            'settings' => 'array',
            'ethiopian_calendar' => 'boolean',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function featureFlags(): HasMany
    {
        return $this->hasMany(FeatureFlag::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function attendanceSetting(): HasOne
    {
        return $this->hasOne(AttendanceSetting::class);
    }

    public function kioskSessions(): HasMany
    {
        return $this->hasMany(KioskSession::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function isTrialExpired(): bool
    {
        return $this->status === TenantStatus::TRIAL
            && $this->trial_ends_at
            && $this->trial_ends_at->isPast();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [TenantStatus::TRIAL, TenantStatus::ACTIVE], true)
            && ! $this->isTrialExpired();
    }
}
