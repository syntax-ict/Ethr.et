<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

/**
 * Names who acted and what was acted on in each audit row, by public id.
 *
 * `AuditLogResource` exposed the numeric `user_id` and `auditable_id`, which
 * convention 4 forbids — and which told an admin nothing: the page read
 * "User #42" and "Employee#17".
 *
 * This is not a `with('user', 'auditable')` for two reasons. The platform
 * audit view runs with no tenant resolved, so every tenant-owned relation
 * fails closed and would name nobody. And `auditable_type` is a class name
 * frozen at write time: a model deleted since (audit B1 removed some) makes a
 * morph eager-load throw for the whole page.
 *
 * Both lookups drop the tenant scope so they work in that platform view, and
 * each states `tenant_id` itself, taken from the audit row — so neither can
 * name a row from a tenant the audit row does not belong to.
 */
final class AuditSubjects
{
    /**
     * Sets the `user` and `auditable` relations on every log, to the model or
     * to null when it cannot be resolved.
     *
     * @param  Collection<int, AuditLog>  $logs
     */
    public static function attach(Collection $logs): void
    {
        foreach ($logs->groupBy(fn (AuditLog $log): string => (string) $log->tenant_id) as $tenantKey => $rows) {
            $tenantId = $tenantKey === '' ? null : (int) $tenantKey;

            self::attachUsers($rows, $tenantId);

            foreach ($rows->groupBy(fn (AuditLog $log): string => (string) $log->auditable_type) as $type => $subjects) {
                self::attachSubjects($subjects, $type, $tenantId);
            }
        }
    }

    /**
     * Sets the `organisation` relation on every log: the tenant the row was
     * written in, or null for a platform action.
     *
     * Only the platform view calls this. Its trail mixes every organisation's
     * rows, and without the name a reader could not tell whose sign-in or whose
     * payroll approval a row recorded. `Tenant` is on the Global Model List, so
     * there is no tenant scope to drop; a deleted organisation still names its
     * own history.
     *
     * @param  Collection<int, AuditLog>  $logs
     */
    public static function attachOrganisations(Collection $logs): void
    {
        $ids = $logs->pluck('tenant_id')->filter()->unique()->values();

        $tenants = $ids->isEmpty()
            ? collect()
            : Tenant::withTrashed()->whereIn('id', $ids)->get(['id', 'public_id', 'name'])->keyBy('id');

        foreach ($logs as $log) {
            $log->setRelation('organisation', $tenants->get($log->tenant_id));
        }
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     */
    private static function attachUsers(Collection $rows, ?int $tenantId): void
    {
        $ids = $rows->pluck('user_id')->filter()->unique()->values();

        // A platform administrator has no tenant, so their own rows are
        // matched by a null tenant_id rather than the audit row's. A user's
        // name is their employee's (User::name()), so the employee is loaded
        // here under the same predicate — left to the accessor, it would
        // lazy-load through the tenant scope and fail closed in the platform
        // view, naming everyone by e-mail.
        $users = $ids->isEmpty()
            ? collect()
            : User::withoutGlobalScope('tenant')
                ->withTrashed()
                ->whereIn('id', $ids)
                ->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
                ->with(['employee' => fn ($query) => $query->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)])
                ->get()
                ->keyBy('id');

        foreach ($rows as $log) {
            $log->setRelation('user', $users->get($log->user_id));
        }
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     */
    private static function attachSubjects(Collection $rows, string $type, ?int $tenantId): void
    {
        $models = collect();
        $traits = class_exists($type) && is_subclass_of($type, Model::class)
            ? class_uses_recursive($type)
            : [];

        if (in_array(HasPublicId::class, $traits, true)) {
            $ids = $rows->pluck('auditable_id')->filter()->unique()->values();
            $prototype = new $type;
            // A deleted subject is still the subject of its history.
            $query = in_array(SoftDeletes::class, $traits, true)
                ? $prototype->newQueryWithoutScope(SoftDeletingScope::class)
                : $prototype->newQuery();

            if (in_array(BelongsToTenant::class, $traits, true)) {
                $query->withoutGlobalScope('tenant')->where('tenant_id', $tenantId);
            }

            $models = $query->whereIn($prototype->getKeyName(), $ids)->get()->keyBy($prototype->getKeyName());
        }

        foreach ($rows as $log) {
            $log->setRelation('auditable', $models->get($log->auditable_id));
        }
    }
}
