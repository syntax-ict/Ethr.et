<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
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
 * One rule for the executive dashboard and the analytics drill-down that sits
 * on the same page (audit N85).
 */
final class ExecutiveBranchScope
{
    public static function resolve(Request $request): ?int
    {
        $user = $request->user();
        $hasFull = $user?->hasPermission('dashboard.executive') ?? false;
        $hasRegional = $user?->hasPermission('dashboard.regional') ?? false;

        if (! $hasFull && ! $hasRegional) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        if ($hasFull) {
            if (! $request->filled('branch')) {
                return null;
            }

            $branch = Branch::where('public_id', $request->input('branch'))->first();

            if (! $branch) {
                throw new HttpException(404, 'Branch not found.');
            }

            return $branch->id;
        }

        // Regional: forced to the caller's own branch, never the caller's choice.
        $branchId = $user->employee?->branch_id;

        if ($branchId === null) {
            throw new HttpException(403, 'No branch is assigned to your account.');
        }

        return $branchId;
    }
}
