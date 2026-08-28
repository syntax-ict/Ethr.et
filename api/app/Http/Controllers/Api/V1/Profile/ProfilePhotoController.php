<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UploadProfilePhotoRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The employee's own profile photo.
 *
 * Separate from `PUT /profile` because a multipart body cannot ride on a PUT: PHP
 * only populates $_FILES for POST, so the `photo` rule that used to sit on the
 * update request was unreachable.
 */
class ProfilePhotoController extends Controller
{
    public function __construct(private readonly FileStorageService $storage) {}

    public function store(UploadProfilePhotoRequest $request): JsonResponse
    {
        $employee = $this->employeeOrNull($request);

        if (! $employee instanceof Employee) {
            return $this->noEmployeeRecord();
        }

        $previousPath = $employee->photo_path;

        $uploaded = $this->storage->upload($request->file('photo'), 'photos');
        $employee->update(['photo_path' => $uploaded['path']]);

        // Best effort: an orphaned old photo is storage waste, not a failed upload.
        if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $uploaded['path']) {
            $this->storage->delete($previousPath);
        }

        AuditLog::record('profile.photo_updated', $employee, ['path' => $uploaded['path']]);

        return response()->json([
            'photo_path' => $uploaded['path'],
            'photo_url' => $this->storage->temporaryUrlOrNull($uploaded['path']),
            'photo_thumb_url' => $this->storage->thumbnailUrlOrNull($uploaded['path'], 150),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $employee = $this->employeeOrNull($request);

        if (! $employee instanceof Employee) {
            return $this->noEmployeeRecord();
        }

        $path = $employee->photo_path;

        if (is_string($path) && $path !== '') {
            $this->storage->delete($path);
            $employee->update(['photo_path' => null]);
            AuditLog::record('profile.photo_removed', $employee, ['path' => $path]);
        }

        return response()->json(null, 204);
    }

    private function employeeOrNull(Request $request): ?Employee
    {
        $employee = $request->user()?->employee;

        return $employee instanceof Employee ? $employee : null;
    }

    private function noEmployeeRecord(): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/no-employee-record',
            'title' => 'No Employee Record',
            'status' => 422,
            'detail' => 'No employee record linked to your account.',
        ], 422);
    }
}
