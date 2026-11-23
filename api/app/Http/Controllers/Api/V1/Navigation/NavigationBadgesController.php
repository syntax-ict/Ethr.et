<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Navigation;

use App\Http\Controllers\Controller;
use App\Models\AttendanceIntegrityFlag;
use App\Services\Approval\DecidableApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NavigationBadgesController extends Controller
{
    /**
     * Navigation badge counts.
     *
     * Pending approvals and attendance punches waiting for review, the two
     * numbers the sidebar and the phone tab bar draw. Each is null when the
     * caller may not see that queue.
     */
    public function __invoke(Request $request, DecidableApprovals $approvals): JsonResponse
    {
        $user = $request->user();

        $approvalCount = Gate::allows('leave.viewTeam')
            ? (int) $approvals->counts($user)['total']
            : null;

        $integrityCount = Gate::allows('attendance.viewConflicts')
            ? (int) AttendanceIntegrityFlag::openFor($user)->count()
            : null;

        return response()->json([
            'approvals' => $approvalCount,
            'attendance_integrity' => $integrityCount,
        ]);
    }
}
