<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Enums\ProfileUpdateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ReviewProfileUpdateRequest;
use App\Http\Resources\ProfileUpdateRequestResource;
use App\Models\ProfileUpdateRequest;
use App\Services\Profile\ProfileUpdateRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * HR review queue for the profile fields employees may propose but not apply.
 *
 * The employee's own view of their submissions is on `GET /profile` — this
 * controller is the reviewer's side.
 */
class ProfileUpdateRequestController extends Controller
{
    public function __construct(private readonly ProfileUpdateRequestService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewPending', ProfileUpdateRequest::class);

        $status = $request->query('status', ProfileUpdateStatus::PENDING->value);

        $query = ProfileUpdateRequest::query()
            ->with(['employee:id,public_id,name', 'requester.employee:id,name', 'reviewer.employee:id,name'])
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== 'all') {
            $query->where('status', $status);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);

        return ProfileUpdateRequestResource::collection($query->paginate($perPage));
    }

    /**
     * The employee retracts their own pending request.
     *
     * Ownership is the authorisation here — an employee has no `employee.update`
     * permission, and the lookup is scoped to their own employee record so another
     * employee's request id resolves to a 404 rather than a retraction.
     */
    public function withdraw(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        $profileUpdate = $employee
            ? ProfileUpdateRequest::where('public_id', $publicId)
                ->where('employee_id', $employee->id)
                ->first()
            : null;

        if (! $profileUpdate instanceof ProfileUpdateRequest) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'Profile update request not found.',
            ], 404);
        }

        if ($profileUpdate->status !== ProfileUpdateStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/already-reviewed',
                'title' => 'Already Reviewed',
                'status' => 422,
                'detail' => 'This profile update request has already been reviewed.',
            ], 422);
        }

        $profileUpdate = $this->service->withdraw($profileUpdate, $user);

        return response()->json(
            (new ProfileUpdateRequestResource(
                $profileUpdate->load(['employee', 'requester.employee', 'reviewer.employee'])
            ))->resolve($request)
        );
    }

    public function review(ReviewProfileUpdateRequest $request, string $publicId): JsonResponse
    {
        Gate::authorize('review', ProfileUpdateRequest::class);

        $profileUpdate = ProfileUpdateRequest::where('public_id', $publicId)->firstOrFail();

        if ($profileUpdate->status !== ProfileUpdateStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/already-reviewed',
                'title' => 'Already Reviewed',
                'status' => 422,
                'detail' => 'This profile update request has already been reviewed.',
            ], 422);
        }

        $notes = $request->validated()['notes'] ?? null;

        $profileUpdate = $request->validated()['action'] === 'approve'
            ? $this->service->approve($profileUpdate, $request->user(), $notes)
            : $this->service->reject($profileUpdate, $request->user(), $notes);

        return response()->json(
            (new ProfileUpdateRequestResource(
                $profileUpdate->load(['employee', 'requester.employee', 'reviewer.employee'])
            ))->resolve($request)
        );
    }
}
