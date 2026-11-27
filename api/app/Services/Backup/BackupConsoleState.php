<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\BackupDriveSetting;
use App\Models\BackupStorageSetting;
use App\Services\Backup\GoogleDrive\GoogleDriveOAuth;
use App\Support\BackupHealth;
use App\Support\BackupResidency;

/**
 * What the Backups page of the platform console shows: the primary storage,
 * the Google Drive fallback and the health of the copies. One place, because
 * both controllers answer every write with the whole page's state.
 *
 * No secret, token or key is ever in it.
 */
class BackupConsoleState
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupHealth $health,
    ) {}

    /** @return array<string, mixed> */
    public function state(): array
    {
        $settings = BackupStorageSetting::current();
        $primary = $this->backups->primaryTarget();
        $blocker = $this->backups->offHostBlocker();

        $source = match (true) {
            $settings instanceof BackupStorageSetting => 'console',
            ! in_array($primary['blocker'], ['off_host_disk_missing', 'off_host_not_configured'], true) => 'env',
            default => 'none',
        };

        return [
            'source' => $source,
            'enabled' => $settings instanceof BackupStorageSetting && $settings->enabled,
            'available' => $blocker === null,
            'blocker' => $blocker,
            'keep' => (int) config('backup.off_host_keep', 14),
            'residency_enforced' => BackupResidency::enforced(),
            'storage' => $settings instanceof BackupStorageSetting ? [
                'endpoint' => $settings->endpoint,
                'region' => $settings->region,
                'bucket' => $settings->bucket,
                'access_key_id' => $settings->access_key_id,
                'secret_set' => $settings->hasSecret(),
                'path_style' => $settings->path_style,
                'located_in_ethiopia' => $settings->located_in_ethiopia,
                'located_confirmed_at' => $settings->located_confirmed_at?->toIso8601String(),
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
            'google_drive' => $this->drive(),
            'health' => $this->health->snapshot(),
        ];
    }

    /** @return array<string, mixed> */
    private function drive(): array
    {
        $drive = BackupDriveSetting::current();
        $target = collect($this->backups->offHostTargets())->first(fn (array $t): bool => $t['kind'] === 'google_drive');

        return [
            'redirect_uri' => GoogleDriveOAuth::redirectUri(),
            'saved' => $drive instanceof BackupDriveSetting,
            'client_id' => $drive?->client_id,
            'secret_set' => $drive?->hasSecret() ?? false,
            'connected' => $drive?->isConnected() ?? false,
            'account_email' => $drive?->account_email,
            'connected_at' => $drive?->connected_at?->toIso8601String(),
            'enabled' => $drive->enabled ?? false,
            'available' => $target !== null && $target['blocker'] === null,
            'blocker' => $target['blocker'] ?? null,
            'verified' => $drive?->isVerified() ?? false,
            'verified_at' => $drive?->verified_at?->toIso8601String(),
            'last_test_at' => $drive?->last_test_at?->toIso8601String(),
            'last_test_ok' => $drive?->last_test_ok,
            'last_test_error' => $drive?->last_test_error,
            'key' => $drive instanceof BackupDriveSetting ? [
                'revealed_at' => $drive->key_revealed_at?->toIso8601String(),
                'saved_at' => $drive->key_saved_at?->toIso8601String(),
            ] : null,
        ];
    }
}
