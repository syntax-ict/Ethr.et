<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BackupDriveCallbackRequest;
use App\Http\Requests\Admin\SaveBackupDriveRequest;
use App\Http\Requests\Admin\UpdateBackupSettingsRequest;
use App\Models\AuditLog;
use App\Models\BackupDriveSetting;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupConsoleState;
use App\Services\Backup\BackupDriveDisk;
use App\Services\Backup\GoogleDrive\GoogleDriveClient;
use App\Services\Backup\GoogleDrive\GoogleDriveException;
use App\Services\Backup\GoogleDrive\GoogleDriveOAuth;
use App\Support\BackupResidency;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Google Drive as the fallback destination for off-host backups, connected
 * by the super admin in the platform console with their own Google OAuth
 * client. Every route sits behind `admin.manage` like the rest of the console,
 * including the OAuth return: Google sends the browser back to the console
 * page, and the page posts `code` and `state` here through the normal API
 * client.
 *
 * The switch follows the same order as the S3 storage: connect, test, confirm
 * the key is saved, then switch on. The client secret, the refresh token and
 * the key never appear in a response; the key leaves only through revealKey().
 */
class BackupDriveController extends Controller
{
    private const STATE_TTL_MINUTES = 10;

    public function save(SaveBackupDriveRequest $request, BackupConsoleState $console): JsonResponse
    {
        Gate::authorize('admin.manage');

        $data = $request->validated();
        $secretReplaced = filled($data['client_secret'] ?? null);

        if (! $secretReplaced) {
            unset($data['client_secret']);
        }

        $settings = BackupDriveSetting::current() ?? new BackupDriveSetting;
        $created = ! $settings->exists;
        $settings->fill($data);

        if ($created) {
            $settings->encryption_key = BackupCipher::generateKey();
        }

        $changed = array_keys($settings->getDirty());
        $reconnect = array_intersect($changed, BackupDriveSetting::CONNECTION_FIELDS) !== [] && ! $created;
        $switchedOff = $reconnect && $settings->enabled;

        if ($reconnect) {
            $this->forgetConnection($settings);
        }

        $settings->save();

        AuditLog::record('platform.backup_drive_updated', $settings, [
            'changed' => array_values(array_diff($changed, ['client_secret', 'encryption_key'])),
            'secret_replaced' => $secretReplaced,
            'key_generated' => $created,
            'connection_cleared' => $reconnect,
            'copies_switched_off' => $switchedOff,
        ]);

        return response()->json($console->state());
    }

    /** The Google consent URL the browser is sent to. */
    public function connect(): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupDriveSetting::current();
        abort_unless($settings instanceof BackupDriveSetting, 404);

        $state = Str::random(40);
        Cache::put($this->stateKey($state), (string) auth()->id(), now()->addMinutes(self::STATE_TTL_MINUTES));

        return response()->json(['url' => GoogleDriveOAuth::authorizationUrl((string) $settings->client_id, $state)]);
    }

    public function callback(BackupDriveCallbackRequest $request, BackupConsoleState $console): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupDriveSetting::current();
        abort_unless($settings instanceof BackupDriveSetting, 404);

        // Single use, and bound to the person who asked for it.
        $owner = Cache::pull($this->stateKey((string) $request->input('state')));

        if (! is_string($owner) || ! hash_equals($owner, (string) auth()->id())) {
            throw ValidationException::withMessages(['state' => __('backup.drive_state_invalid')]);
        }

        try {
            $tokens = GoogleDriveOAuth::exchangeCode((string) $settings->client_id, (string) $settings->client_secret, (string) $request->input('code'));
        } catch (GoogleDriveException $e) {
            throw ValidationException::withMessages(['code' => __('backup.drive_exchange_failed', ['reason' => $e->getMessage()])]);
        }

        if ($tokens['refresh_token'] === null) {
            throw ValidationException::withMessages(['code' => __('backup.drive_no_refresh_token')]);
        }

        $client = new GoogleDriveClient((string) $settings->client_id, (string) $settings->client_secret, $tokens['refresh_token'], '');
        $client->rememberAccessToken($tokens['access_token'], $tokens['expires_in']);

        try {
            $folderId = $client->createFolder('ETHR backups');
        } catch (GoogleDriveException $e) {
            throw ValidationException::withMessages(['code' => __('backup.drive_folder_failed', ['reason' => $e->getMessage()])]);
        }

        // The folder id is part of the client, so the email lookup, which is
        // only a label and never fails the connection, uses a client that has it.
        $email = (new GoogleDriveClient((string) $settings->client_id, (string) $settings->client_secret, $tokens['refresh_token'], $folderId))->accountEmail();

        $settings->forceFill([
            'refresh_token' => $tokens['refresh_token'],
            'folder_id' => $folderId,
            'account_email' => $email,
            'connected_at' => now(),
            'verified_at' => null,
            'enabled' => false,
        ])->save();

        AuditLog::record('platform.backup_drive_connected', $settings, ['account_known' => $email !== null]);

        return response()->json($console->state());
    }

    public function test(BackupDriveDisk $disk, BackupConsoleState $console): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupDriveSetting::current();
        abort_unless($settings instanceof BackupDriveSetting, 404);

        $error = $disk->probe($settings);

        $settings->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => $error === null,
            'last_test_error' => $error,
            'verified_at' => $error === null ? now() : $settings->verified_at,
        ])->save();

        AuditLog::record('platform.backup_drive_tested', $settings, ['ok' => $error === null]);

        return response()->json($console->state());
    }

    /**
     * Drive's own archive key. Audited and rate limited as the primary's is:
     * with the key and a copy anyone can read every tenant's data.
     */
    public function revealKey(): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupDriveSetting::current();
        abort_unless($settings instanceof BackupDriveSetting, 404);

        $settings->forceFill(['key_revealed_at' => now()])->save();

        AuditLog::record('platform.backup_drive_key_revealed', $settings);

        return response()->json(['key' => $settings->encryption_key]);
    }

    public function updateSettings(UpdateBackupSettingsRequest $request, BackupConsoleState $console): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupDriveSetting::current();
        abort_unless($settings instanceof BackupDriveSetting, 404);

        // Drive stores outside Ethiopia (Proclamation 1321/2024 Art. 22(1)), so
        // production never switches copies to it on. Switching it off, and
        // reading back what it already holds, stay allowed.
        if ($request->boolean('enabled') && BackupResidency::refuses(false)) {
            throw ValidationException::withMessages(['enabled' => __('backup.drive_outside_ethiopia')]);
        }

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
            AuditLog::record('platform.backup_drive_updated', $settings, ['changed' => $changed]);
        }

        return response()->json($console->state());
    }

    /** Forget the connection: the refresh token is revoked at Google, best effort. */
    public function disconnect(BackupConsoleState $console): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = BackupDriveSetting::current();
        abort_unless($settings instanceof BackupDriveSetting, 404);

        $token = $settings->refresh_token;
        $this->forgetConnection($settings);
        $settings->save();

        if (is_string($token) && $token !== '') {
            GoogleDriveOAuth::revoke($token);
        }

        AuditLog::record('platform.backup_drive_disconnected', $settings);

        return response()->json($console->state());
    }

    /** Back to "saved, not connected, switched off". The key and the client stay. */
    private function forgetConnection(BackupDriveSetting $settings): void
    {
        $settings->refresh_token = null;
        $settings->account_email = null;
        $settings->folder_id = null;
        $settings->connected_at = null;
        $settings->verified_at = null;
        $settings->enabled = false;
    }

    private function stateKey(string $state): string
    {
        return 'backup-drive:oauth-state:'.hash('sha256', $state);
    }
}
