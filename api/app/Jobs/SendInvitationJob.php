<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InvitationSend;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Invitations\EmailInvitationCeiling;
use App\Services\Invitations\InvitationDispatcher;
use App\Services\Sms\ConfiguredSmsSender;
use App\Services\UserProvisioningService;
use App\Support\FrontendUrl;
use App\Support\Mail\MailBudget;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

/**
 * One queued invitation (InvitationDispatcher::queue), sent by email, or by
 * text message once those are on.
 *
 * The payload holds two ids and nothing else. The activation token is made in
 * `handle()`, so the jobs table never holds one. `tenantId` is required and
 * stated on every lookup (QueuedJobTenantPredicateTest): a worker resolves no
 * tenant, and a row id alone proves nothing about whose row it is.
 *
 * Waiting is not failing. A wait for the organisation's per-minute limit, or
 * for tomorrow's share of the SMS cap, is a release; only a real delivery
 * error counts towards `maxExceptions`. `retryUntil` bounds the whole thing.
 *
 * An email over the day's ceiling (EmailInvitationCeiling) is held, not
 * released: the row gets a later `held_until` and a fresh job is queued for
 * then. A release would keep this job's `retryUntil`, which is fixed when the
 * job is dispatched, so one held on two days in a row would expire and be
 * marked failed. A fresh job starts a fresh two days.
 */
final class SendInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 4;

    /** One SMTP conversation; the queue's retry_after (90 s) must exceed it. */
    public int $timeout = 60;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $invitationSendId,
    ) {
        $this->onQueue('notifications');
    }

    /** Two days after the job is due, which is later than now for a job held for a later day. */
    public function retryUntil(): DateTimeInterface
    {
        $due = $this->delay instanceof DateTimeInterface ? Carbon::instance($this->delay) : now();

        return $due->copy()->addDays(2);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(
        CurrentTenant $currentTenant,
        UserProvisioningService $provisioning,
        ConfiguredSmsSender $sms,
        InvitationDispatcher $dispatcher,
        EmailInvitationCeiling $ceiling,
    ): void {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        $currentTenant->set($tenant);

        $send = InvitationSend::query()->where('tenant_id', $this->tenantId)->find($this->invitationSendId);
        if ($send === null || $send->status !== InvitationSend::QUEUED) {
            return;
        }

        // Another job, delayed to `held_until`, carries this row. A run before
        // then is a duplicate, or the `sync` connection, which ignores the delay
        // and would otherwise hold and re-dispatch without end.
        if ($send->held_until?->isFuture() === true) {
            return;
        }

        $user = User::query()->where('tenant_id', $this->tenantId)->find($send->user_id);
        if ($user === null || $user->status !== 'invited') {
            $this->finish($send, InvitationSend::SKIPPED, 'not_invited');

            return;
        }

        if ($send->channel === InvitationSend::EMAIL && ! $ceiling->roomToday($this->tenantId)) {
            $slot = $ceiling->planner($this->tenantId, $dispatcher->perMinute(InvitationSend::EMAIL), fromTomorrow: true)->next();

            if ($slot instanceof Carbon) {
                $send->update(['held_until' => $slot]);
                self::dispatch($this->tenantId, $send->id)->delay($slot);

                return;
            }
        }

        $limit = 'invitations:'.$send->channel.':'.$this->tenantId;
        if (RateLimiter::tooManyAttempts($limit, $dispatcher->perMinute($send->channel))) {
            $this->release(RateLimiter::availableIn($limit) + 1);

            return;
        }
        RateLimiter::hit($limit, 60);

        if ($send->channel === InvitationSend::SMS) {
            if (! is_string($user->phone) || trim($user->phone) === '' || ! $sms->invitationsEnabled()) {
                // Texts were switched off after this was queued: the person is
                // listed for an in-person code again, and nothing is sent.
                $this->finish($send, InvitationSend::SKIPPED, 'texts_off');

                return;
            }

            if (! $sms->invitationBudgetLeft()) {
                $this->release($this->secondsUntilTomorrow());

                return;
            }

            $send->increment('attempts');

            if (! $sms->sendInvitation($user->phone, $this->text($tenant, $user))) {
                throw new RuntimeException('The SMS gateway did not accept the invitation.');
            }
        } else {
            if (! is_string($user->email) || trim($user->email) === '') {
                $this->finish($send, InvitationSend::SKIPPED, 'no_email');

                return;
            }

            $send->increment('attempts');

            $provisioning->deliverActivationEmail($user, $tenant);
            MailBudget::recordInvitation();
        }

        $send->update(['status' => InvitationSend::SENT, 'sent_at' => now(), 'held_until' => null, 'error_code' => null]);
    }

    public function failed(Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        app(CurrentTenant::class)->set($tenant);

        InvitationSend::query()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->invitationSendId)
            ->where('status', InvitationSend::QUEUED)
            ->update(['status' => InvitationSend::FAILED, 'error_code' => 'delivery']);

        // The address and the gateway's answer stay out of the log line.
        Log::error('Invitation could not be delivered after retries', [
            'invitation_send_id' => $this->invitationSendId,
            'tenant_id' => $this->tenantId,
            'exception' => $e::class,
        ]);
    }

    private function finish(InvitationSend $send, string $status, string $errorCode): void
    {
        $send->update(['status' => $status, 'error_code' => $errorCode]);
    }

    /** No link and no token: the code that signs them in is sent when they ask for it. */
    private function text(Tenant $tenant, User $user): string
    {
        return __('user.invitation.sms', [
            'org' => $tenant->name,
            'url' => FrontendUrl::at($tenant, 'login'),
        ], $user->preferredLocale() ?? 'en');
    }

    /** The SMS cap is counted per day in Addis Ababa (SmsSend::countToday). */
    private function secondsUntilTomorrow(): int
    {
        $now = now('Africa/Addis_Ababa');

        return (int) $now->diffInSeconds($now->copy()->addDay()->startOfDay(), true) + 300;
    }
}
