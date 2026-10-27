<?php

declare(strict_types=1);

namespace App\Services\NextSteps;

use App\Enums\NextStepId;
use App\Enums\NextStepSeverity;
use App\Models\NextStepDismissal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NextSteps\Providers\ApprovalsProvider;
use App\Services\NextSteps\Providers\OperationsProvider;
use App\Services\NextSteps\Providers\ProfileProvider;
use App\Services\NextSteps\Providers\RecordsProvider;
use App\Services\NextSteps\Providers\SetupProvider;
use App\Services\NextSteps\Providers\TeamProvider;
use Illuminate\Support\Carbon;

/**
 * One list of next steps for one person.
 *
 * Each provider decides for itself whether this person should see its steps;
 * this class only orders what they return and hides what the person has put
 * away. Blockers first, then warnings, then information, and inside a severity
 * the order of `NextStepId`.
 *
 * A dismissal hides a step only while the step still looks the way it did
 * (same signature), so three requests becoming five brings the row back. A
 * blocker cannot be put away for good: it is hidden only by a snooze that has
 * not run out, however its row was written.
 */
final class NextStepsService
{
    /** The longest a blocker may be put away, in days. */
    public const BLOCKER_SNOOZE_MAX_DAYS = 7;

    /** The longest anything may be put away, in days. */
    public const SNOOZE_MAX_DAYS = 30;

    public function forUser(User $user, Tenant $tenant): NextStepsResult
    {
        $setup = app(SetupProvider::class);

        $providers = [
            app(ProfileProvider::class),
            app(ApprovalsProvider::class),
            app(TeamProvider::class),
            app(RecordsProvider::class),
            $setup,
            app(OperationsProvider::class),
        ];

        $steps = [];
        foreach ($providers as $provider) {
            array_push($steps, ...$provider->steps($user, $tenant));
        }

        usort($steps, static fn (NextStep $a, NextStep $b): int => [$a->severity()->rank(), $a->id->order()]
            <=> [$b->severity()->rank(), $b->id->order()]);

        $away = NextStepDismissal::query()->where('user_id', $user->id)->get()->keyBy('step_id');
        $now = Carbon::now();

        $visible = array_values(array_filter(
            $steps,
            fn (NextStep $step): bool => ! $this->isAway($step, $away->get($step->id->value), $now),
        ));

        return new NextStepsResult($visible, $setup->summary($user, $tenant));
    }

    /**
     * Put a step away. `$snoozeDays` of null is a dismissal that lasts until the
     * step's signature changes; a blocker must name a snooze.
     */
    public function dismiss(User $user, NextStepId $step, string $signature, ?int $snoozeDays): NextStepDismissal
    {
        return NextStepDismissal::query()->updateOrCreate(
            ['user_id' => $user->id, 'step_id' => $step->value],
            [
                'signature' => $signature,
                'snoozed_until' => $snoozeDays === null ? null : Carbon::now()->addDays($snoozeDays),
            ],
        );
    }

    /** Bring back everything this person put away. */
    public function restore(User $user): int
    {
        return NextStepDismissal::query()->where('user_id', $user->id)->delete();
    }

    private function isAway(NextStep $step, ?NextStepDismissal $row, Carbon $now): bool
    {
        if ($row === null || $row->signature !== $step->signature) {
            return false;
        }

        if ($row->snoozed_until !== null) {
            return $row->snoozed_until->greaterThan($now);
        }

        return $step->severity() !== NextStepSeverity::Blocker;
    }
}
