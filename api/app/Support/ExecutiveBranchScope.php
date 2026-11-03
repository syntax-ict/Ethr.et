<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrgScope;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The branch an executive-style read is scoped to, decided by permission:
 *
 *  - `dashboard.executive`: tenant-wide (null), or the branch named by
 *    `?branch=` (a public id; 404 when unknown).
 *  - `dashboard.regional`: always the caller's own `employees.branch_id`; any
 *    `?branch=` is ignored, since honouring it would defeat the scoping. 403
 *    when the caller has no branch.
 *  - neither: 403.
 *
 * A permission says what a caller may see, the org scope says whose, and a
 * dashboard may not reach further than the scope does. A branch view needs a
 * branch-wide scope, a tenant-wide view an unrestricted one. Supervisors and
 * department admins held `dashboard.regional`, so a department head read the
 * other departments of their branch (every department, in a one-branch
 * organisation) through the executive dashboard and its drill-down, names and
 * average salary included. An executive whose role reaches one branch is held
 * to that branch.
 *
 * One rule for the executive dashboard, the analytics drill-down that sits on
 * the same page (audit N85), digests and alert thresholds.
 */
final class ExecutiveBranchScope
{
    /** Tenant-wide executive figures: the permission and a scope that reaches everyone. */
    public static function isFull(?User $user): bool
    {
        return $user !== null
            && $user->hasPermission('dashboard.executive')
            && $user->orgScope() === OrgScope::ALL;
    }

    /** Branch figures only, forced to the caller's own branch. */
    public static function isRegional(?User $user): bool
    {
        return $user !== null
            && ! self::isFull($user)
            && ($user->hasPermission('dashboard.executive') || $user->hasPermission('dashboard.regional'))
            && $user->orgScope()->breadth() >= OrgScope::BRANCH->breadth();
    }

    public static function resolve(Request $request): ?int
    {
        return self::resolveFor(
            $request->user(),
            $request->filled('branch') ? (string) $request->input('branch') : null,
        );
    }

    public static function resolveFor(?User $user, ?string $branchPublicId): ?int
    {
        if (self::isFull($user)) {
            if ($branchPublicId === null) {
                return null;
            }

            $branch = Branch::where('public_id', $branchPublicId)->first();

            if (! $branch) {
                throw new HttpException(404, 'Branch not found.');
            }

            return $branch->id;
        }

        if (! self::isRegional($user)) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        // Regional: forced to the caller's own branch, never the caller's choice.
        $branchId = $user->employee?->branch_id;

        if ($branchId === null) {
            throw new HttpException(403, 'No branch is assigned to your account.');
        }

        return $branchId;
    }
}
