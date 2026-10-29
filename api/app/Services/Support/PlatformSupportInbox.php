<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\UserRole;
use App\Models\SupportRequest;
use App\Models\SupportRequestMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The platform console's reads of support requests: every request an
 * organisation passed to ETHR, from the platform host, where no tenant is
 * resolved and the fail-closed scope would find nothing.
 *
 * Every bypass of the tenant scope in support lives here, so the inventory
 * names one file. The console's list is cross-tenant by intent and reads
 * only escalated requests; everything about one request states that
 * request's `tenant_id`. The routes sit behind `admin.manage`,
 * EnsurePlatformContext and RequirePlatformMfa.
 */
final class PlatformSupportInbox
{
    /** @return LengthAwarePaginator<int, SupportRequest> */
    public function page(?string $status, int $perPage): LengthAwarePaginator
    {
        $query = $this->escalated()
            // The requester belongs to the request's organisation: the key is
            // the request's own column, so the lookup derives from it, and the
            // employee (where every name is read) from the user's own key.
            ->with(['requester' => fn ($q) => $q->withoutGlobalScopes()
                ->with(['employee' => fn ($e) => $e->withoutGlobalScopes()])])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at');

        if ($status !== null && in_array($status, SupportRequest::STATUSES, true)) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->escalated()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n): int => (int) $n)
            ->all();

        return $counts;
    }

    public function find(string $publicId): SupportRequest
    {
        return $this->escalated()->where('public_id', $publicId)->firstOrFail();
    }

    /** @return Collection<int, SupportRequestMessage> */
    public function messages(SupportRequest $request): Collection
    {
        return SupportRequestMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $request->tenant_id)
            ->where('support_request_id', $request->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * The people one request names, keyed by id: its requester and the
     * colleague who passed it on (the organisation's), and the authors of its
     * messages, who may be ETHR's own platform accounts (no tenant).
     *
     * @param  array<int, int|null>  $ids
     * @return Collection<int, User>
     */
    public function people(SupportRequest $request, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where(fn (Builder $q) => $q->where('tenant_id', $request->tenant_id)->orWhereNull('tenant_id'))
            // A name is read through the employee, which the fail-closed scope
            // hides here; without it every name read as the login email.
            ->with(['employee' => fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $request->tenant_id)])
            ->get()
            ->keyBy('id');
    }

    /**
     * Who hears about a request passed to ETHR: the platform's super admins,
     * who belong to no tenant.
     *
     * @return Collection<int, User>
     */
    public function platformAdmins(): Collection
    {
        return User::query()
            ->withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('role', UserRole::SUPER_ADMIN->value)
            ->get();
    }

    /** @return Builder<SupportRequest> */
    private function escalated(): Builder
    {
        return SupportRequest::query()->withoutGlobalScopes()->whereNotNull('escalated_at');
    }
}
