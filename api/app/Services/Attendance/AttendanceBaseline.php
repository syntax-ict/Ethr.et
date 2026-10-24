<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\OrganizationProvisioner;
use App\Services\Onboarding\WorkforceProfileCatalog;

/**
 * Attendance that works from an organisation's first minute.
 *
 * Until 2026-10-24 sign-up created no shift, no settings and no holidays.
 * Every punch was stored PENDING (nothing to rate it against) while every
 * weekday without a punch, public holidays included, counted as an absence.
 * Smart configuration in the onboarding wizard fixed that only for those who
 * ran it.
 *
 * The baseline is the industry's usual schedule, from the same catalogue the
 * wizard uses, so there is one source of defaults:
 *
 *  - A profile everyone works (office hours, a six-day week) is applied as is.
 *  - A crew profile (24/7, rotating shifts) cannot be: each crew needs its own
 *    rotation anchor, which is knowledge of people. Its shifts and rotations
 *    are created ready to assign, and office hours are the default for
 *    everyone not yet on a crew.
 *  - Nothing the admin must consent to or that changes pay is switched on
 *    (selfies, automatic check-out); the wizard offers those explicitly.
 *
 * Create-only and idempotent: an organisation with any active shift is left
 * alone except that a missing default is restored. Applying a profile in the
 * wizard later replaces the baseline while nothing has used it
 * (OrganizationProvisioner::replaceableBaseline).
 */
final class AttendanceBaseline
{
    public function __construct(
        private readonly OrganizationProvisioner $provisioner,
        private readonly IndustryCatalog $industries,
        private readonly WorkforceProfileCatalog $profiles,
        private readonly CurrentTenant $currentTenant,
    ) {}

    /** What ensure() did: 'provisioned', 'healed' or 'none'. */
    public function ensure(Tenant $tenant): string
    {
        $previous = $this->currentTenant->get();
        $this->currentTenant->set($tenant);

        try {
            AttendanceSetting::query()->firstOrCreate([], AttendanceSetting::defaults());

            if (! Shift::query()->where('is_active', true)->exists()) {
                $this->provision($tenant);

                return 'provisioned';
            }

            return $this->healDefault() ? 'healed' : 'none';
        } finally {
            $previous !== null ? $this->currentTenant->set($previous) : $this->currentTenant->forget();
        }
    }

    /** The workforce profile an organisation of this kind usually works. */
    public function profileKeyFor(Tenant $tenant): string
    {
        $industry = $this->industryFor($tenant);

        return $industry !== null && $this->profiles->has($industry['profile'])
            ? $industry['profile']
            : WorkforceProfileCatalog::DEFAULT;
    }

    private function provision(Tenant $tenant): void
    {
        $profile = $this->profiles->find($this->profileKeyFor($tenant));
        $office = $this->profiles->find(WorkforceProfileCatalog::DEFAULT);

        if ($profile === null || $office === null) {
            return;
        }

        $crew = $this->isCrewProfile($profile);

        $shifts = $crew ? [...$office['shifts'], ...$profile['shifts']] : $profile['shifts'];
        $workingDays = $crew ? $office['working_days'] : $profile['working_days'];

        $this->provisioner->apply($tenant, [
            'shifts' => $shifts,
            'rotations' => $profile['rotations'],
            'settings' => ['working_days' => $workingDays],
        ]);

        $tenant->refresh();
        $settings = $tenant->settings ?? [];
        $settings['attendance_baseline'] = [
            'profile' => $profile['key'],
            'shifts' => array_values(array_map(static fn (array $shift): string => (string) $shift['name'], $shifts)),
            'rotations' => array_values(array_map(static fn (array $rotation): string => (string) $rotation['name'], $profile['rotations'])),
            'applied_at' => now()->toIso8601String(),
        ];
        $tenant->update(['settings' => $settings]);
    }

    /**
     * A profile is a crew profile when it has a rotation that is not the
     * whole organisation's default week.
     *
     * @param  array{rotations: list<array<string, mixed>>}  $profile
     */
    private function isCrewProfile(array $profile): bool
    {
        foreach ($profile['rotations'] as $rotation) {
            if (($rotation['default'] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active shifts but no default schedule: the oldest active shift becomes
     * the default, so people with no shift of their own are rated again.
     */
    private function healDefault(): bool
    {
        $hasDefaultRotation = ShiftRotation::query()->where('is_default', true)->where('is_active', true)->exists();
        $active = Shift::query()->where('is_active', true);

        if ($hasDefaultRotation || (clone $active)->where('is_default', true)->exists()) {
            return false;
        }

        $oldest = (clone $active)->orderBy('id')->first();
        $oldest?->update(['is_default' => true]);

        return $oldest !== null;
    }

    /**
     * The industry chosen in the wizard, else the organisation type chosen at
     * sign-up: an industry key, or a catalogue `base` (the sign-up form offers
     * `government`, `bank`, `hospital`, ...).
     *
     * @return array{key: string, profile: string}|null
     */
    private function industryFor(Tenant $tenant): ?array
    {
        foreach ([data_get($tenant->settings, 'industry'), $tenant->type] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            if ($this->industries->has($candidate)) {
                $found = $this->industries->find($candidate);

                return $found === null ? null : ['key' => $found['key'], 'profile' => $found['profile']];
            }

            // A base covers several industries; the profile most of them
            // work is the honest guess (office hours for `general`).
            $profiles = [];
            foreach ($this->industries->all() as $entry) {
                if ($entry['base'] === $candidate) {
                    $profiles[$entry['profile']] = ($profiles[$entry['profile']] ?? 0) + 1;
                }
            }

            if ($profiles !== []) {
                arsort($profiles);

                return ['key' => $candidate, 'profile' => (string) array_key_first($profiles)];
            }
        }

        return null;
    }
}
