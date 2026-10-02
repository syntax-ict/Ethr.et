<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Best-effort notification delivery from inside a business operation.
 *
 * Mirrors `DispatchesWebhooks`, and for the same reason: notifications fire
 * inline from HTTP requests, and the broadcast channel talks to Reverb over
 * cURL while mail talks to an SMTP host. When either is down, an unguarded send
 * throws *after* the business write has already committed — so the user is told
 * their leave request failed when it was in fact created. That exact failure has
 * now been observed twice in this codebase (the REVERB_HOST incident and the
 * profile-update 500).
 *
 * The record of what happened is the database row; delivery is a courtesy on top.
 *
 * ## Which pattern to use (audit B10)
 *
 * There are two ways to send in this codebase, and the choice depends on where
 * the send runs, not on taste:
 *
 * - **In an HTTP request, after a write: use this trait's `notify()`.** A throw
 *   there would report failure for a change that has already committed.
 *   `AttendanceCorrectionController` called `->notify()` bare and returned 500
 *   for committed corrections until 2026-10-01. A request path that already
 *   wraps the send in its own try/catch (password reset, account activation,
 *   the contact form, `ProfileUpdateRequestService::deliver()`) is equivalent,
 *   and is left as it is.
 * - **In a queued job or listener, decide by what a throw would do.** A direct
 *   `$user->notify(...)` lets the throw fail the job, and the worker retries
 *   it. That is right when the job is safe to run again and the notification
 *   is the point (device alerts, approval reminders). Use this trait when the
 *   send follows state the job has already persisted, or when one recipient
 *   must not stop the rest. `RunScheduledReportsJob` and
 *   `RunDashboardDigestsJob` write `last_error` first and must not lose it.
 *   The announcement and payroll fan-outs must reach everyone else.
 *
 * Neither pattern filters anything. Per-user channel preferences are applied
 * by each notification's `via()` (`filterChannels()`), so both paths respect
 * them. And neither sets tenant context: a notification carries what it needs
 * in its constructor.
 */
trait SendsNotifications
{
    /**
     * The user account behind an employee, or null.
     *
     * Employee relations carry no PHPStan generics in this codebase, so
     * `$employee->user` statically resolves to a bare Model. Narrowing here keeps
     * every caller honest without widening the relation signatures, which the
     * PHPStan baseline depends on.
     */
    protected function userOf(mixed $employee): ?User
    {
        if (! $employee instanceof Employee) {
            return null;
        }

        $user = $employee->user;

        return $user instanceof User ? $user : null;
    }

    /** The user account of an employee's supervisor, or null. */
    protected function supervisorUserOf(mixed $employee): ?User
    {
        return $employee instanceof Employee
            ? $this->userOf($employee->supervisor)
            : null;
    }

    /**
     * @param  iterable<mixed>|object|null  $notifiables
     */
    protected function notify(mixed $notifiables, Notification $notification): void
    {
        if ($notifiables === null) {
            return;
        }

        // A collection that filtered down to nothing is not an error, just a
        // tenant with no one holding the relevant role.
        if (is_iterable($notifiables) && count(iterator_to_array($notifiables)) === 0) {
            return;
        }

        try {
            NotificationFacade::send($notifiables, $notification);
        } catch (\Throwable $e) {
            Log::warning('Notification could not be delivered', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
