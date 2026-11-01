<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBackupStorageRequest;
use App\Http\Requests\Admin\UpdateBackupSettingsRequest;
use App\Models\AuditLog;
use App\Models\BackupStorageSetting;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupStorageDisk;
use App\Support\BackupHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Off-host backup storage, configured by the super admin in the platform
 * console instead of `.env`: an S3-compatible bucket, a connection test, the
 * encryption key and the on/off switch. Behind `admin.manage` on the platform
 * host with MFA, like every other console endpoint.
 *
 * The switch turns on only while the storage has passed a test and the admin
 * has confirmed the key is saved away from the host: an encrypted copy whose
 * only key was on the lost host cannot be opened. Editing how the storage
 * connects clears the test and switches copies off, as SMS does.
 *
 * The secret is write-only and no response carries it. The key is generated
 * here, never typed, and leaves only through revealKey(), which is audited.
 */
class BackupStorageController extends Controller
{
    public function show(BackupService $backups, BackupHealth $health): JsonResponse
    {
        Gate::authorize('admin.manage');

        return response()->json($this->state($backups, $health));
    }

    public function saveStorage(SaveBackupStorageRequest $request, BackupService $backups, BackupHealth $health): JsonResponse
    {
        Gate::authorize('admin.manage');

        $data = $request->validated();
        $secretReplaced = filled($data['secret_access_key'] ?? null);

        if (! $secretReplaced) {
            unset($data['secret_access_key']);
        }

        $settings = BackupStorageSetting::current() ?? new BackupStorageSetting;
        $created = ! $settings->exists;
        $settings->fill($data);

        if ($created) {
            $settings->encryption_key = BackupCipher::generateKey();
        }

        $changed = array_keys($settings->getDirty());
        $reconnected = array_intersect($changed, BackupStorageSetting::CONNECTION_FIELDS) !== [];
        $switchedOff = $reconnected && $settings->enabled;

        if ($reconnected) {
            $settings->verified_at = null;
            $settings->enabled = false;
        }

        $settings->save();

        AuditLog::record('platform.backup_storage_updated', $settings, [
            'changed' => array_values(array_diff($changed, ['secret_access_key', 'encryption_key'])),
            'secret_replaced' => $secretReplaced,
            'key_generated' => $created,
            'verification_cleared' => $reconnected,
            'copies_switched_off' => $switchedOff,
        ]);

        return response()->json($this->state($backups, $health));
    }

    public function updateSettings(UpdateBackupSettingsRequest $request, BackupService $backups, BackupHealth $health): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupStorageSetting::current();
        abort_unless($settings instanceof BackupStorageSetting, 404);

        if ($request->has('key_saved')) {
            $settings->key_saved_at = $request->boolean('key_saved') ? ($settings->key_saved_at ?? now()) : null;
        }

        if ($request->has('enabled')) {
            $settings->enabled = $request->boolean('enabled');
        }

        // Withdrawing the confirmation withdraws the switch with it.
        if (! $settings->isKeySaved()) {
            if ($request->boolean('enabled')) {
                throw ValidationException::withMessages(['enabled' => __('backup.enable_requires_key_saved')]);
            }
            $settings->enabled = false;
        }

        if ($settings->enabled && ! $settings->isVerified()) {
            throw ValidationException::withMessages(['enabled' => __('backup.enable_requires_verified')]);
        }

        $changed = array_keys($settings->getDirty());
        $settings->save();

        if ($changed !== []) {
            AuditLog::record('platform.backup_storage_updated', $settings, ['changed' => $changed]);
        }

        return response()->json($this->state($backups, $health));
    }

    public function testStorage(BackupStorageDisk $disk, BackupService $backups, BackupHealth $health): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupStorageSetting::current();
        abort_unless($settings instanceof BackupStorageSetting, 404);

        $error = $disk->probe($settings);

        $settings->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => $error === null,
            'last_test_error' => $error,
            'verified_at' => $error === null ? now() : $settings->verified_at,
        ])->save();

        AuditLog::record('platform.backup_storage_tested', $settings, ['ok' => $error === null]);

        return response()->json($this->state($backups, $health));
    }

    /**
     * The archive key, for the admin to keep away from the host. The console
     * already demands MFA; this is audited and rate limited on top, because
     * with the key and a copy anyone can read every tenant's data.
     */
    public function revealKey(): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupStorageSetting::current();
        abort_unless($settings instanceof BackupStorageSetting, 404);

        $settings->forceFill(['key_revealed_at' => now()])->save();

        AuditLog::record('platform.backup_key_revealed', $settings);

        return response()->json(['key' => $settings->encryption_key]);
    }

    /** @return array<string, mixed> */
    private function state(BackupService $backups, BackupHealth $health): array
    {
        $settings = BackupStorageSetting::current();
        $target = $backups->offHostTarget();

        $source = match (true) {
            $settings instanceof BackupStorageSetting => 'console',
            ! in_array($target['blocker'], ['off_host_disk_missing', 'off_host_not_configured'], true) => 'env',
            default => 'none',
        };

        return [
            'source' => $source,
            'enabled' => $settings instanceof BackupStorageSetting && $settings->enabled,
            'available' => $target['blocker'] === null,
            'blocker' => $target['blocker'],
            'keep' => (int) config('backup.off_host_keep', 14),
            'storage' => $settings instanceof BackupStorageSetting ? [
                'endpoint' => $settings->endpoint,
                'region' => $settings->region,
                'bucket' => $settings->bucket,
                'access_key_id' => $settings->access_key_id,
                'secret_set' => filled($settings->secret_access_key),
                'path_style' => $settings->path_style,
                'verified' => $settings->isVerified(),
                'verified_at' => $settings->verified_at?->toIso8601String(),
                'last_test_at' => $settings->last_test_at?->toIso8601String(),
                'last_test_ok' => $settings->last_test_ok,
                'last_test_error' => $settings->last_test_error,
            ] : null,
            'key' => $settings instanceof BackupStorageSetting ? [
                'revealed_at' => $settings->key_revealed_at?->toIso8601String(),
                'saved_at' => $settings->key_saved_at?->toIso8601String(),
            ] : null,
            'health' => $health->snapshot(),
        ];
    }
}
