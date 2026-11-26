<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\TitleAccessHeld;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TitleAccessHeldNotification;
use App\Services\Access\PositionAccess;
use App\Services\CurrentTenant;
use App\Support\RoleAssignment;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyTitleAccessHeld implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(
        private readonly PositionAccess $access,
        private readonly CurrentTenant $current,
    ) {}

    public function handle(TitleAccessHeld $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        // A queue drain resolves no organisation, so this listener sets the one
        // the sweep stated and reads only through the tenant scope: it drops no
        // scope of its own. It forgets it again, because the next job on this
        // worker is another organisation's.
        $this->current->set($tenant);

        try {
            // The queue drains later than the sweep ran: an account that was
            // applied in between is not waiting any more.
            $held = $this->access->heldQuery()
                ->whereIn('users.id', $event->userIds)
                ->with('employee.position')
                ->get();

            if ($held->isEmpty()) {
                return;
            }

            $candidates = User::query()
                ->whereIn('role', [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value])
                ->where('status', 'active')
                ->get();

            // Each person is told only about the accounts they may give the
            // role to, by the same rule as the Users page: not their own, not
            // one above them, not a role at their own level below the
            // organisation admin. A notice to someone who cannot act on it
            // would point at a page where nothing is theirs to do.
            foreach ($candidates as $candidate) {
                $count = $held->filter(function (User $account) use ($candidate): bool {
                    $to = $this->access->previewRole($account);

                    return $to !== null && RoleAssignment::mayChange($candidate, $account, $to);
                })->count();

                if ($count > 0) {
                    $candidate->notify(new TitleAccessHeldNotification($count));
                }
            }
        } finally {
            $this->current->forget();
        }
    }
}
