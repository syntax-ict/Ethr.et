<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Enums\ProfileUpdateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\EmergencyContactResource;
use App\Http\Resources\ProfileUpdateRequestResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ProfileUpdateRequest;
use App\Services\FileStorageService;
use App\Services\Profile\ProfileUpdateRequestService;
use App\Support\EthiopianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly ProfileUpdateRequestService $profileUpdates,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        // One query per relation instead of six lazy round-trips, and it keeps the
        // endpoint inside preventLazyLoading regardless of how the user was resolved.
        $employee?->loadMissing(['department', 'position', 'branch', 'grade', 'supervisor']);
        $photoPath = $employee?->photo_path;

        return response()->json([
            'user' => [
                'public_id' => $user->public_id,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'role' => $user->role,
                'status' => $user->status,
                'mfa_enabled' => (bool) $user->mfa_enabled,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'preferences' => ProfilePreferencesController::present($user),
            'employee' => $employee ? [
                'public_id' => $employee->public_id,
                'name' => $employee->name,
                'name_am' => $employee->name_am,
                'employee_code' => $employee->employee_code,
                'phone' => $employee->phone,
                'gender' => $employee->gender,
                'date_of_birth' => $employee->date_of_birth?->format('Y-m-d'),
                'nationality' => $employee->nationality,
                'marital_status' => $employee->marital_status,
                'hire_date' => $employee->hire_date?->format('Y-m-d'),
                // Cast to EmployeeStatus on the model; a backed enum serialises to
                // its value on the way out.
                'status' => $employee->status,
                // The TIN is an encrypted column and a gated field: the employee has
                // to see what is on record to know whether it needs correcting, but
                // only ever the tail of it.
                'tin_masked' => $this->mask($employee->tin),
                'photo_path' => $photoPath,
                'photo_url' => $this->storage->temporaryUrlOrNull($photoPath),
                'photo_thumb_url' => $this->storage->thumbnailUrlOrNull($photoPath, 150),
                'department' => $employee->department?->name,
                // `positions` names its column `title`; reading `name` here returned
                // null for every employee, so the profile has always shown no position.
                'position' => $employee->position?->title,
                'branch' => $employee->branch?->name,
                'grade' => $employee->grade?->name,
                'supervisor' => $employee->supervisor?->name,
            ] : null,
            // Contacts and bank details live in their own tables, and until they were
            // returned here the edit form had nothing to prefill from — an employee
            // updating one emergency-contact field silently blanked the rest.
            'emergency_contacts' => $employee instanceof Employee
                ? EmergencyContactResource::collection(
                    $employee->emergencyContacts()->orderBy('priority')->get()
                )->resolve($request)
                : [],
            'bank_details' => $employee instanceof Employee
                ? $employee->bankDetails()
                    ->orderByDesc('is_primary')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($detail) => [
                        'public_id' => $detail->public_id,
                        'bank_name' => $detail->bank_name,
                        'branch_name' => $detail->branch_name,
                        'account_number_masked' => $this->mask($detail->account_number),
                        'is_primary' => (bool) $detail->is_primary,
                    ])->all()
                : [],
            // The employee's own view of what they have proposed. Without this the
            // profile page has no way to distinguish "my change is queued" from
            // "my change was ignored" — which is precisely how this used to behave.
            // The relations are the ones ProfileUpdateRequestResource reads; without
            // eager-loading them this 500s under `preventLazyLoading`, which is on
            // everywhere except production.
            'pending_updates' => $employee instanceof Employee
                ? ProfileUpdateRequestResource::collection(
                    $employee->profileUpdateRequests()
                        ->with(['employee:id,public_id,name', 'requester.employee:id,name', 'reviewer.employee:id,name'])
                        ->where('status', ProfileUpdateStatus::PENDING)
                        ->orderByDesc('created_at')
                        ->get()
                )->resolve($request)
                : [],
            // Decided requests, so "HR rejected this and here is why" reaches the
            // employee on the page and not only in a notification they may have
            // dismissed. Capped — this is a recent-activity list, not an archive.
            'recent_updates' => $employee instanceof Employee
                ? ProfileUpdateRequestResource::collection(
                    $employee->profileUpdateRequests()
                        ->with(['employee:id,public_id,name', 'requester.employee:id,name', 'reviewer.employee:id,name'])
                        ->where('status', '!=', ProfileUpdateStatus::PENDING)
                        ->orderByDesc('reviewed_at')
                        ->limit(10)
                        ->get()
                )->resolve($request)
                : [],
            // Which side of the approval line each field falls on. Shipped rather
            // than hardcoded in the client so the two can never drift.
            'editable_fields' => [
                'self' => ProfileUpdateRequest::SELF_FIELDS,
                'gated' => array_keys(ProfileUpdateRequest::GATED_FIELDS),
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (! $employee instanceof Employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/no-employee-record',
                'title' => 'No Employee Record',
                'status' => 422,
                'detail' => 'No employee record linked to your account.',
            ], 422);
        }

        $data = $request->validated();

        // Emergency-contact details belong to the employee_emergency_contacts
        // table — pull them out so they never reach $employee->update(), which with
        // strict model guards on throws MassAssignmentException on an unknown
        // attribute. Full contact management is on /profile/emergency-contacts;
        // these three keys stay for the "primary contact" shorthand.
        $emergencyContact = array_filter([
            'name' => $data['emergency_contact_name'] ?? null,
            'phone' => isset($data['emergency_contact_phone'])
                ? EthiopianPhone::canonicalOrRaw($data['emergency_contact_phone'])
                : null,
            'relationship' => $data['emergency_contact_relationship'] ?? null,
        ], fn ($v) => $v !== null);
        unset(
            $data['emergency_contact_name'],
            $data['emergency_contact_phone'],
            $data['emergency_contact_relationship'],
        );

        $gatedFields = array_keys(ProfileUpdateRequest::GATED_FIELDS);
        $sensitiveChanges = array_filter(
            array_intersect_key($data, array_flip($gatedFields)),
            fn ($v) => $v !== null,
        );
        $allowedChanges = array_diff_key($data, array_flip($gatedFields));

        // Apply allowed (non-sensitive) employee fields immediately.
        $employeeChanges = array_filter($allowedChanges, fn ($v) => $v !== null);
        if (! empty($employeeChanges)) {
            // Store the identifier phone in one canonical shape (+251…) regardless
            // of whether the employee typed the local 09… or E.164 form; the user
            // record below is synced from the same canonical value.
            if (isset($employeeChanges['phone'])) {
                $employeeChanges['phone'] = EthiopianPhone::canonicalOrRaw($employeeChanges['phone']);
            }

            $employee->update($employeeChanges);

            // Sync phone to user record too
            if (isset($employeeChanges['phone'])) {
                $user->update(['phone' => $employeeChanges['phone']]);
            }

            AuditLog::record('profile.updated', $employee, $employeeChanges);
        }

        // Upsert the employee's primary emergency contact (separate table).
        if ($emergencyContact !== []) {
            $contact = $employee->emergencyContacts()->orderBy('priority')->first();
            if ($contact) {
                $contact->update($emergencyContact);
            } else {
                $employee->emergencyContacts()->create([
                    'tenant_id' => $employee->tenant_id,
                    'name' => $emergencyContact['name'] ?? '',
                    'phone' => $emergencyContact['phone'] ?? '',
                    'relationship' => $emergencyContact['relationship'] ?? 'other',
                    'priority' => 1,
                ]);
            }

            AuditLog::record('profile.emergency_contact_updated', $employee, $emergencyContact);
        }

        // Gated fields are staged for HR review rather than applied. Before the
        // profile_update_requests table existed this branch reported the change as
        // "pending approval" and then discarded the value entirely.
        $pendingRequest = null;
        if ($sensitiveChanges !== []) {
            $staged = $this->profileUpdates->stage($employee, $user, $sensitiveChanges);

            if ($staged->isNotEmpty()) {
                $pendingRequest = [
                    'status' => 'pending_approval',
                    'fields' => $staged->pluck('field_name')->all(),
                    'requests' => ProfileUpdateRequestResource::collection($staged)->resolve(),
                    'message' => 'Changes to sensitive fields require HR approval.',
                ];
            }
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'pending_approval' => $pendingRequest,
            'was_duplicate' => false,
        ]);
    }

    /**
     * Last four characters only, for values the employee owns but should not have
     * re-broadcast in full on every page load.
     */
    private function mask(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $tail = mb_substr($value, -4);

        return str_repeat('•', max(0, mb_strlen($value) - 4)).$tail;
    }
}
