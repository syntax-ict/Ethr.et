<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingProgress extends Model
{
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function markStepComplete(int $step, array $data = []): void
    {
        $completed = $this->completed_steps ?? [];
        if (!in_array($step, $completed, true)) {
            $completed[] = $step;
        }

        $stepData = $this->step_data ?? [];
        $stepData["step_{$step}"] = $data;

        $this->update([
            'completed_steps' => $completed,
            'step_data' => $stepData,
            'current_step' => min($step + 1, 7),
        ]);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
