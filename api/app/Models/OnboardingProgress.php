<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingStep;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;

class OnboardingProgress extends Model
{
    use BelongsToTenant, HasAuditLog;

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

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
