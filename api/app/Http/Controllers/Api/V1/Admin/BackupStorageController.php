<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBackupStorageRequest;
use App\Http\Requests\Admin\UpdateBackupSettingsRequest;
use App\Models\AuditLog;
use App\Models\BackupStorageSetting;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupConsoleState;
use App\Services\Backup\BackupStorageDisk;
use App\Support\MaintenanceLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

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
    public function show(BackupConsoleState $console): JsonResponse
    {
        Gate::authorize('admin.manage');

        return response()->json($console->state());
    }

    public function saveStorage(SaveBackupStorageRequest $request, BackupConsoleState $console): JsonResponse
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

        // Who stated where the storage is, and when: restated with every save
        // that moves it, and withdrawn with the statement.
        if (! $settings->located_in_ethiopia) {
            $settings->located_confirmed_at = null;
            $settings->located_confirmed_by = null;
        } elseif ($settings->isDirty('located_in_ethiopia') || array_intersect(array_keys($settings->getDirty()), BackupStorageSetting::CONNECTION_FIELDS) !== []) {
            $settings->located_confirmed_at = now();
            $settings->located_confirmed_by = $request->user()?->getKey();
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
            'located_in_ethiopia' => $settings->located_in_ethiopia,
        ]);

        return response()->json($console->state());
    }

    public function updateSettings(UpdateBackupSettingsRequest $request, BackupConsoleState $console): JsonResponse
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

        return response()->json($console->state());
    }

    public function testStorage(BackupStorageDisk $disk, BackupConsoleState $console): JsonResponse
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

        return response()->json($console->state());
    }

    /**
     * Back up now, from the console: the same `ethr:backup` the nightly run
     * performs (room check, off-host copy, prune, disk measure), so the button
     * proves what the schedule would do. `--off-host` is not passed: it makes
     * the command fail when no destination is configured, and a local backup
     * is worth taking on its own.
     *
     * Live `max_execution_time` is 30 seconds and a backup plus an upload
     * outlasts it, so the limit is lifted and a closed tab does not stop it.
     * It takes the lock the release-step maintenance tasks take, so the two
     * cannot overlap. Known gap, accepted: the 01:00 scheduled run takes a
     * different one (the scheduler's `withoutOverlapping()` mutex), so a click
     * at exactly 01:00 could overlap it.
     *
     * A failure is reported in the body, never as a 500.
     *
     * @response array{source: 'console'|'env'|'none', enabled: bool, available: bool, blocker: string|null, keep: int, residency_enforced: bool, storage: array<string, mixed>|null, key: array<string, mixed>|null, google_drive: array<string, mixed>, health: array<string, mixed>, run: array{ok: bool, exit_code: int|null, output: string, off_host_copied: bool}}
     */
    public function run(BackupConsoleState $console): JsonResponse
    {
        Gate::authorize('admin.manage');

        ignore_user_abort(true);
        @set_time_limit(0);

        $lock = MaintenanceLock::make();

        if (! $lock->get()) {
            return response()->json([
                'message' => __('backup.run_already_running'),
                'run' => ['ok' => false, 'exit_code' => null, 'output' => '', 'off_host_copied' => false],
            ], 409);
        }

        $copiedBefore = $console->state()['health']['off_host']['last_copied_at'] ?? null;
        $exitCode = 1;
        $output = '';

        try {
            $exitCode = Artisan::call('ethr:backup', ['--label' => 'console']);
            $output = Artisan::output();
        } catch (Throwable $e) {
            report($e);
            $output = 'Backup FAILED: '.$e->getMessage();
        } finally {
            $lock->release();
        }

        $state = $console->state();
        $copiedAfter = $state['health']['off_host']['last_copied_at'] ?? null;
        $offHostCopied = $copiedAfter !== null && $copiedAfter !== $copiedBefore;

        AuditLog::record('platform.backup_run', null, [
            'exit_code' => $exitCode,
            'off_host_copied' => $offHostCopied,
        ]);

        $outcome = ['ok' => $exitCode === 0, 'exit_code' => $exitCode, 'output' => trim($output), 'off_host_copied' => $offHostCopied];

        return response()->json([...$state, 'run' => $outcome]);
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
}
