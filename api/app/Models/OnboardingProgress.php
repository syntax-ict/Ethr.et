<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\OnboardingStep;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OnboardingProgress extends Model
{
    use BelongsToTenant;

    protected $table = 'onboarding_progress';

    protected $fillable = [
        'tenant_id',
        'current_step',
        'completed_steps',
        'step_data',
        'completed_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'completed_steps' => 'array',
            'step_data' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The steps done, with People counted as soon as the organisation has a
     * current employee, the same test as the readiness check "Employees
     * added". Only the wizard's own commit records that step, so employees
     * added on the Employees page, by import or through the API left an
     * organisation with a full staff list at "3 of 4 done" for good.
     *
     * @return list<int>
     */
    public static function completedStepsFor(?self $progress, int $tenantId): array
    {
        $steps = array_map('intval', (array) ($progress->completed_steps ?? []));
        $people = OnboardingStep::WORKFORCE_MIGRATION->value;

        if (! in_array($people, $steps, true)
            && Employee::query()->where('tenant_id', $tenantId)
                ->whereIn('status', EmployeeStatus::current())->exists()) {
            $steps[] = $people;
        }

        return $steps;
    }

    public function markStepComplete(int $step, array $data = []): void
    {
        $completed = $this->completed_steps ?? [];
        if (! in_array($step, $completed, true)) {
            $completed[] = $step;
        }

        $stepData = $this->step_data ?? [];
        $stepData["step_{$step}"] = $data;

        $this->update([
            'completed_steps' => $completed,
            'step_data' => $stepData,
            'current_step' => min($step + 1, OnboardingStep::last()->value),
        ]);
    }
}
