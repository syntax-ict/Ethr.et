<?php

declare(strict_types=1);

namespace App\Services\NextSteps\Providers;

use App\Enums\EmployeeStatus;
use App\Enums\Feature;
use App\Enums\NextStepId;
use App\Enums\ProfileUpdateStatus;
use App\Models\ProfileUpdateRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Features\FeatureGate;
use App\Services\NextSteps\NextStep;
use App\Services\NextSteps\StepProvider;

/**
 * What the person can still add to their own profile.
 *
 * Only what they could act on counts. TIN and bank details are asked for only
 * when the organisation runs payroll, since nothing else reads them. A field
 * with a change request already waiting on HR is not missing: the person did
 * their part, and nagging for it again would send them to file a duplicate.
 * A colleague who has left, or is suspended, is not asked for anything.
 */
final class ProfileProvider implements StepProvider
{
    public function __construct(private readonly FeatureGate $features) {}

    public function steps(User $user, Tenant $tenant): array
    {
        $employee = $user->employee;

        if ($employee === null || ! in_array($employee->status, EmployeeStatus::current(), true)) {
            return [];
        }

        $waiting = ProfileUpdateRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', ProfileUpdateStatus::PENDING)
            ->pluck('field_name')
            ->all();

        $done = [
            'photo' => filled($employee->photo_path),
            'phone' => filled($employee->phone),
            'date_of_birth' => $employee->date_of_birth !== null || in_array('date_of_birth', $waiting, true),
            'emergency_contact' => $employee->emergencyContacts()->exists(),
        ];

        if ($this->features->allows($tenant, Feature::Payroll)) {
            $done['tin'] = filled($employee->tin) || in_array('tin', $waiting, true);
            $done['bank'] = $employee->bankDetails()->exists()
                || array_intersect(['bank_name', 'bank_account_number'], $waiting) !== [];
        }

        $missing = array_keys(array_filter($done, static fn (bool $has): bool => ! $has));

        if ($missing === []) {
            return [];
        }

        return [new NextStep(
            NextStepId::CompleteProfile,
            '/profile/personal',
            implode(',', $missing),
            count($missing),
            count($done),
            $missing,
        )];
    }
}
