<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\AuditLog;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly FileStorageService $storage) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        return response()->json([
            'user' => [
                'public_id' => $user->public_id,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
            ],
            'employee' => $employee ? [
                'public_id' => $employee->public_id,
                'name' => $employee->name,
                'name_am' => $employee->name_am,
                'phone' => $employee->phone,
                'gender' => $employee->gender,
                'date_of_birth' => $employee->date_of_birth?->format('Y-m-d'),
                'nationality' => $employee->nationality,
                'marital_status' => $employee->marital_status,
                'hire_date' => $employee->hire_date?->format('Y-m-d'),
                'photo_path' => $employee->photo_path,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'branch' => $employee->branch?->name,
                'grade' => $employee->grade?->name,
            ] : null,
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/no-employee-record',
                'title' => 'No Employee Record',
                'status' => 422,
                'detail' => 'No employee record linked to your account.',
            ], 422);
        }

        $data = $request->validated();
        $sensitiveFields = ['name', 'bank_account_number', 'bank_name'];
        $sensitiveChanges = array_intersect_key($data, array_flip($sensitiveFields));
        $allowedChanges = array_diff_key($data, array_flip($sensitiveFields));

        // Handle photo upload
        if ($request->hasFile('photo')) {
            $uploaded = $this->storage->upload($request->file('photo'), 'photos');
            $allowedChanges['photo_path'] = $uploaded['path'];
        }

        // Apply allowed fields immediately
        if (! empty($allowedChanges)) {
            $employee->update(array_filter($allowedChanges, fn ($v) => $v !== null));

            // Sync phone to user record too
            if (isset($allowedChanges['phone'])) {
                $user->update(['phone' => $allowedChanges['phone']]);
            }

            AuditLog::record('profile.updated', $employee, $allowedChanges);
        }

        // Sensitive fields create a pending request
        $pendingRequest = null;
        if (! empty($sensitiveChanges)) {
            $pendingRequest = [
                'status' => 'pending_approval',
                'fields' => array_keys($sensitiveChanges),
                'message' => 'Changes to sensitive fields require HR approval.',
            ];

            AuditLog::record('profile.sensitive_change_requested', $employee, $sensitiveChanges);
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'pending_approval' => $pendingRequest,
            'was_duplicate' => false,
        ]);
    }
}
