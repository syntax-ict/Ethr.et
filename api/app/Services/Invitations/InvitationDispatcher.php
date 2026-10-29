<?php

declare(strict_types=1);

namespace App\Services\Invitations;

use App\Jobs\SendInvitationJob;
use App\Models\AuditLog;
use App\Models\InvitationSend;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Sms\ConfiguredSmsSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Invitations to sign in, for many people at once: an import, a device
 * migration, "resend to everyone not activated", the daily reminders.
 *
 * Nothing is sent in the request. Each invitation is a row and a queued job,
 * spaced out at the organisation's per-minute limit, so 300 people do not mean
 * 300 mail conversations inside a 30-second request (the host's limit) and one
 * organisation does not fill every queue drain.
 *
 * One invitation for one person (the employee form, the resend button) is still
 * sent at once by UserProvisioningService::sendActivationLink(), because
 * someone may be standing there waiting for it.
 */
final class InvitationDispatcher
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly ConfiguredSmsSender $sms,
    ) {}

    /**
     * How this person can be invited. Email when they have one; a text message
     * when they have only a phone and the platform has text invitations on;
     * otherwise in person, with a code HR issues.
     */
    public function channelFor(User $user): string
    {
        if (is_string($user->email) && trim($user->email) !== '') {
            return InvitationSend::EMAIL;
        }

        if (is_string($user->phone) && trim($user->phone) !== '' && $this->sms->invitationsEnabled()) {
            return InvitationSend::SMS;
        }

        return InvitationSend::IN_PERSON;
    }

    /** Whether a person with only a phone is invited by text (true) or in person (false). */
    public function textsEnabled(): bool
    {
        return $this->sms->invitationsEnabled();
    }

    /**
     * Queue an invitation for each person who is still waiting to activate.
     *
     * Skipped: anyone not `invited`, anyone from another organisation, and
     * anyone with an invitation queued or sent in the last few minutes (a
     * double click, or "resend to everyone" pressed twice, sends once). People
     * who can only be invited in person are counted, not queued.
     *
     * @param  iterable<User>  $users
     * @return array{batch: string|null, queued: int, in_person: int, skipped: int}
     */
    public function queue(iterable $users, ?User $by = null, bool $reminder = false): array
    {
        $tenant = $this->currentTenant->get();
        if ($tenant === null) {
            throw new RuntimeException('Cannot queue invitations without a resolved tenant.');
        }

        /** @var Collection<int, User> $users */
        $users = collect($users);

        $recent = InvitationSend::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', $users->pluck('id')->all())
            ->whereIn('status', [InvitationSend::QUEUED, InvitationSend::SENT])
            ->where('queued_at', '>=', now()->subMinutes((int) config('invitations.repeat_after_minutes', 10)))
            ->pluck('user_id')
            ->flip();

        $batch = (string) Str::ulid();
        $counts = ['queued' => 0, 'in_person' => 0, 'skipped' => 0];
        $position = [InvitationSend::EMAIL => 0, InvitationSend::SMS => 0];

        foreach ($users->unique('id') as $user) {
            if ($user->tenant_id !== $tenant->id || $user->status !== 'invited' || $recent->has($user->id)) {
                $counts['skipped']++;

                continue;
            }

            $channel = $this->channelFor($user);
            if ($channel === InvitationSend::IN_PERSON) {
                $counts['in_person']++;

                continue;
            }

            $send = InvitationSend::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'batch' => $batch,
                'channel' => $channel,
                'status' => InvitationSend::QUEUED,
                'reminder' => $reminder,
                'queued_by' => $by?->id,
                'queued_at' => now(),
            ]);

            // Spread over the minutes the per-minute limit implies, so the
            // limiter in the job is a backstop and not the normal path.
            $minute = intdiv($position[$channel]++, $this->perMinute($channel));

            SendInvitationJob::dispatch($tenant->id, $send->id)->delay(now()->addMinutes($minute));

            $counts['queued']++;
        }

        if ($counts['queued'] > 0 || $counts['in_person'] > 0) {
            AuditLog::record('invitation.batch_queued', null, [
                'batch' => $counts['queued'] > 0 ? $batch : null,
                ...$counts,
                'reminder' => $reminder,
            ]);
        }

        return ['batch' => $counts['queued'] > 0 ? $batch : null, ...$counts];
    }

    /**
     * Where a batch stands, for the progress card. Null when the organisation
     * has no batch of that id, which answers 404 whoever's it is.
     *
     * @return array{batch: string, total: int, queued: int, sent: int, failed: int, skipped: int, failures: list<array{user_id: string, name: string|null, channel: string, error_code: string|null}>}|null
     */
    public function summary(string $batch): ?array
    {
        $tenant = $this->currentTenant->get();
        if ($tenant === null) {
            return null;
        }

        $rows = InvitationSend::query()
            ->where('tenant_id', $tenant->id)
            ->where('batch', $batch)
            ->with('user.employee')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $count = fn (string $status): int => $rows->where('status', $status)->count();

        return [
            'batch' => $batch,
            'total' => $rows->count(),
            'queued' => $count(InvitationSend::QUEUED),
            'sent' => $count(InvitationSend::SENT),
            'failed' => $count(InvitationSend::FAILED),
            'skipped' => $count(InvitationSend::SKIPPED),
            'failures' => $rows->where('status', InvitationSend::FAILED)
                ->filter(fn (InvitationSend $row): bool => $row->user !== null)
                ->map(fn (InvitationSend $row): array => [
                    'user_id' => (string) $row->user->public_id,
                    'name' => $row->user->employee->name ?? $row->user->email,
                    'channel' => $row->channel,
                    'error_code' => $row->error_code,
                ])
                ->values()
                ->all(),
        ];
    }

    public function perMinute(string $channel): int
    {
        $key = $channel === InvitationSend::SMS ? 'invitations.sms_per_minute' : 'invitations.per_minute';

        return max(1, (int) config($key, 30));
    }
}
