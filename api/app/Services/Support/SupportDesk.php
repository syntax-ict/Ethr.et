<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\AuditLog;
use App\Models\SupportRequest;
use App\Models\SupportRequestMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SupportRequestNotification;
use App\Support\ReleaseStamp;
use App\Traits\SendsNotifications;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Get help, end to end: a person asks from inside ETHR, the organisation's
 * support people (support.manage) answer first, and pass it to ETHR when it
 * needs the platform. ETHR answers from the console. Every step tells whoever
 * the next move belongs to, by in-app notification and email.
 *
 * Statuses say whose move it is: `open` waits for whoever holds the request,
 * `answered` waits for the person who asked, `resolved` is done. A reply
 * from the person who asked reopens a resolved request.
 *
 * Both controllers call this; the console's reads go through
 * PlatformSupportInbox, which holds every tenant-scope bypass.
 */
final class SupportDesk
{
    use SendsNotifications;

    public const VIEWER_REQUESTER = 'requester';

    public const VIEWER_ORGANISATION = 'organisation';

    public const VIEWER_ETHR = 'ethr';

    public function __construct(private readonly PlatformSupportInbox $inbox) {}

    /**
     * @param  array<string, mixed>  $data  validated StoreSupportRequest input
     */
    public function open(User $user, int $tenantId, array $data, Request $http): SupportRequest
    {
        $toEthr = ($data['send_to'] ?? SupportRequest::HANDLED_BY_ORGANISATION) === SupportRequest::HANDLED_BY_ETHR;
        $includeContext = (bool) ($data['include_context'] ?? true);
        $clientContext = is_array($data['context'] ?? null) ? $data['context'] : [];

        $request = SupportRequest::query()->create([
            'tenant_id' => $tenantId,
            'requester_user_id' => $user->id,
            'category' => $data['category'],
            'body' => trim(strip_tags((string) $data['body'])),
            'status' => 'open',
            'handled_by' => $toEthr ? SupportRequest::HANDLED_BY_ETHR : SupportRequest::HANDLED_BY_ORGANISATION,
            'escalated_at' => $toEthr ? now() : null,
            'escalated_by_user_id' => $toEthr ? $user->id : null,
            'context' => self::context($user, $includeContext ? $clientContext : null, $includeContext ? $http : null),
        ]);

        AuditLog::record('support.request_created', $request, [
            'category' => $request->category,
            'handled_by' => $request->handled_by,
        ]);

        if ($toEthr) {
            $this->notify($this->inbox->platformAdmins(), new SupportRequestNotification($request, 'escalated', self::VIEWER_ETHR, $request->body));
        } else {
            $this->notify($this->organisationHandlers($user), new SupportRequestNotification($request, 'received', self::VIEWER_ORGANISATION, $request->body));
        }

        return $request;
    }

    /** `$author` is one of SupportRequestMessage::AUTHORS. */
    public function reply(SupportRequest $request, string $author, User $user, string $body): SupportRequestMessage
    {
        $message = SupportRequestMessage::query()->create([
            'tenant_id' => $request->tenant_id,
            'support_request_id' => $request->id,
            'author' => $author,
            'author_user_id' => $user->id,
            'body' => trim(strip_tags($body)),
        ]);

        $fromRequester = $author === self::VIEWER_REQUESTER;
        $request->update([
            'status' => $fromRequester ? 'open' : 'answered',
            'resolved_at' => null,
        ]);
        $request->touch();

        if ($fromRequester) {
            $this->notify(
                $request->isWithEthr() ? $this->inbox->platformAdmins() : $this->organisationHandlers($user),
                new SupportRequestNotification($request, 'requester_replied', $request->isWithEthr() ? self::VIEWER_ETHR : self::VIEWER_ORGANISATION, $message->body),
            );

            return $message;
        }

        AuditLog::record('support.request_replied', $request, ['author' => $author]);

        $this->notify($this->requesterUnless($request, $user), new SupportRequestNotification($request, 'answered', self::VIEWER_REQUESTER, $message->body));

        // ETHR's answer also reaches the colleague who passed it on, when that
        // was not the person who asked: they are the one following it up.
        if ($author === self::VIEWER_ETHR && $request->escalated_by_user_id !== null
            && $request->escalated_by_user_id !== $request->requester_user_id) {
            $this->notify(
                $this->inbox->people($request, [$request->escalated_by_user_id])->first(),
                new SupportRequestNotification($request, 'answered', self::VIEWER_ORGANISATION, $message->body),
            );
        }

        return $message;
    }

    public function escalate(SupportRequest $request, User $by, ?string $note): void
    {
        $note = $note === null ? null : (trim(strip_tags($note)) ?: null);

        $request->update([
            'handled_by' => SupportRequest::HANDLED_BY_ETHR,
            'escalated_at' => now(),
            'escalated_by_user_id' => $by->id,
            'escalation_note' => $note,
            'status' => 'open',
            'resolved_at' => null,
        ]);

        AuditLog::record('support.request_escalated', $request, ['category' => $request->category]);

        $this->notify($this->inbox->platformAdmins(), new SupportRequestNotification($request, 'escalated', self::VIEWER_ETHR, $note ?? $request->body));
        $this->notify($this->requesterUnless($request, $by), new SupportRequestNotification($request, 'passed_to_ethr', self::VIEWER_REQUESTER));
    }

    /** `$by` is whoever closed it: one of the three viewers. */
    public function resolve(SupportRequest $request, string $by, User $user): void
    {
        $request->update(['status' => 'resolved', 'resolved_at' => now()]);

        AuditLog::record('support.request_resolved', $request, ['by' => $by]);

        if ($by !== self::VIEWER_REQUESTER) {
            $this->notify($this->requesterUnless($request, $user), new SupportRequestNotification($request, 'resolved', self::VIEWER_REQUESTER));
        }
    }

    /**
     * One request as `$viewer` may see it. `$people` are the users it names,
     * keyed by id; `$messages` is null for a list row.
     *
     * The person who asked sees ETHR's replies as "ETHR support" and never
     * the note their organisation wrote when passing it on.
     *
     * @param  Collection<int, SupportRequestMessage>|null  $messages
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>
     */
    public function present(SupportRequest $request, string $viewer, Collection $people, ?Collection $messages = null, ?Tenant $tenant = null): array
    {
        $staff = $viewer !== self::VIEWER_REQUESTER;
        $requester = $request->requester_user_id === null ? null : $people->get($request->requester_user_id);
        $escalatedBy = $request->escalated_by_user_id === null ? null : $people->get($request->escalated_by_user_id);

        return [
            'public_id' => $request->public_id,
            // Who is reading: the person who asked reads their own request as
            // theirs even when they also hold support.manage.
            'viewer' => $viewer,
            'category' => $request->category,
            'body' => $request->body,
            'status' => $request->status,
            'handled_by' => $request->handled_by,
            'requester' => $requester === null ? null : [
                'name' => $requester->name,
                'email' => $staff ? $requester->email : null,
                'role' => $staff ? $requester->role->value : null,
            ],
            'escalated_at' => $request->escalated_at?->toIso8601String(),
            'escalated_by_name' => $staff ? $escalatedBy?->name : null,
            'escalation_note' => $staff ? $request->escalation_note : null,
            'context' => $request->context,
            'organisation' => $tenant === null ? null : [
                'public_id' => $tenant->public_id,
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
            ],
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
            'resolved_at' => $request->resolved_at?->toIso8601String(),
            'messages' => $messages?->map(function (SupportRequestMessage $m) use ($people, $viewer, $requester): array {
                $author = $m->author_user_id === null ? null : $people->get($m->author_user_id);

                return [
                    'public_id' => $m->public_id,
                    'author' => $m->author,
                    'author_name' => match ($m->author) {
                        self::VIEWER_REQUESTER => $requester?->name,
                        self::VIEWER_ETHR => $viewer === self::VIEWER_ETHR ? $author?->name : null,
                        default => $author?->name,
                    },
                    'body' => $m->body,
                    'created_at' => $m->created_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * What the screen knew when the person asked, kept small and free of
     * anything typed into a search: paths lose their query string. With the
     * person's "include technical details" off, only what the server already
     * knows is kept: their role and the release it was running.
     *
     * @param  array<string, mixed>|null  $client
     * @return array<string, mixed>
     */
    public static function context(User $user, ?array $client, ?Request $http): array
    {
        $server = [
            'role' => $user->role->value,
            'release' => ReleaseStamp::read()['commit'],
        ];

        if ($client === null || $http === null) {
            return $server + ['included' => false];
        }

        $errors = [];
        foreach (array_slice(is_array($client['recent_errors'] ?? null) ? $client['recent_errors'] : [], 0, 5) as $error) {
            if (! is_array($error)) {
                continue;
            }
            $errors[] = [
                'method' => strtoupper((string) ($error['method'] ?? '')),
                'path' => self::path($error['path'] ?? null),
                'status' => (int) ($error['status'] ?? 0),
                'type' => isset($error['type']) ? Str::limit((string) $error['type'], 200, '') : null,
                'at' => isset($error['at']) ? (string) $error['at'] : null,
            ];
        }

        return $server + [
            'included' => true,
            'page' => self::path($client['page'] ?? null),
            'locale' => isset($client['locale']) ? (string) $client['locale'] : null,
            'viewport' => isset($client['viewport']) ? (string) $client['viewport'] : null,
            'online' => isset($client['online']) ? (bool) $client['online'] : null,
            'pending_offline' => isset($client['pending_offline']) ? (int) $client['pending_offline'] : null,
            'recent_errors' => $errors,
            'user_agent' => Str::limit((string) $http->userAgent(), 300, '') ?: null,
        ];
    }

    /**
     * The organisation's support people, less whoever is acting.
     *
     * @return Collection<int, User>
     */
    public function organisationHandlers(?User $except = null): Collection
    {
        return User::query()->get()
            ->filter(fn (User $u): bool => $u->id !== $except?->id && $u->hasPermission('support.manage'))
            ->values();
    }

    private function requesterUnless(SupportRequest $request, User $actor): ?User
    {
        if ($request->requester_user_id === null || $request->requester_user_id === $actor->id) {
            return null;
        }

        return $this->inbox->people($request, [$request->requester_user_id])->first();
    }

    private static function path(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Not strtok(): it skips leading delimiters, so "?q=x" kept "q=x".
        $path = preg_split('/[?#]/', $value, 2)[0] ?? '';

        return $path === '' ? null : Str::limit($path, 200, '');
    }
}
