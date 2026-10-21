<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\ApprovalInstance;
use App\Models\User;
use App\Services\Approval\ApprovalEngine;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A request the approval engine routes. Starting it on creation, rather than
 * in each controller that creates one, means no creation path can skip the
 * workflow: the employee's own form, HR filing on someone's behalf, an
 * import. Whoever is signed in is recorded as the submitter, so they can
 * never be the approver of a step.
 */
trait HasApprovalInstance
{
    public static function bootHasApprovalInstance(): void
    {
        static::created(function (self $approvable): void {
            $by = auth()->user();

            app(ApprovalEngine::class)->start($approvable, $by instanceof User ? $by : null);
        });

        static::updated(function (self $approvable): void {
            if ($approvable->wasChanged('status')) {
                app(ApprovalEngine::class)->close($approvable);
            }
        });
    }

    /** @return MorphOne<ApprovalInstance, $this> */
    public function approvalInstance(): MorphOne
    {
        return $this->morphOne(ApprovalInstance::class, 'approvable');
    }
}
