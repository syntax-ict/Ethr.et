<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Notifications\AttendancePhotosDueForDeletionNotification;
use App\Services\Attendance\PhotoRetention;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Applies the attendance-photo retention policy in every organisation
 * (docs/operations/ATTENDANCE-PHOTO-RETENTION.md).
 *
 * Each run first deletes the selfies whose 48-hour review has ended, then
 * opens the review for selfies past the retention period and tells HR and
 * the department managers once, in one message each. In that order, so a
 * photo is never deleted in the same run that announced it.
 *
 * Idempotent: a record under review is not announced again, and a record
 * whose photos are gone no longer has any to delete.
 */
class AttendancePhotoRetentionCommand extends Command
{
    protected $signature = 'attendance:photo-retention';

    protected $description = 'Delete attendance selfies past their retention period, after notice and review';

    public function handle(CurrentTenant $currentTenant, PhotoRetention $retention): int
    {
        $now = Carbon::now('UTC');
        $deleted = 0;
        $announced = 0;

        Tenant::operational()->each(function (Tenant $tenant) use ($currentTenant, $retention, $now, &$deleted, &$announced): void {
            $currentTenant->set($tenant);

            try {
                $deleted += $retention->deleteDue($now);
                $deleted += $retention->deleteVisitPhotos($now);

                $due = $retention->startReviews($now);
                if ($due->isEmpty()) {
                    return;
                }
                $announced += $due->count();

                $deleteAfter = $now->copy()->addHours(PhotoRetention::REVIEW_HOURS);
                foreach ($retention->reviewers($due) as ['user' => $user, 'count' => $count]) {
                    $user->notify(new AttendancePhotosDueForDeletionNotification($count, $deleteAfter));
                }
            } catch (Throwable $e) {
                // One organisation's bad data must not stop the others.
                report($e);
            } finally {
                $currentTenant->forget();
            }
        });

        $this->info("Deleted the selfies of {$deleted} record(s); opened the review for {$announced}.");

        return self::SUCCESS;
    }
}
