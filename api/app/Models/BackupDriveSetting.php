<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Backup\BackupCipher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The Google Drive fallback for off-host backups, connected by the super admin
 * in the platform console. One row, global by design
 * (TenantIsolationTest::GLOBAL_MODELS), like BackupStorageSetting.
 *
 * `client_secret`, `refresh_token` and `encryption_key` are encrypted at rest
 * and in $hidden. The key is generated here, never typed, and leaves only
 * through BackupDriveController::revealKey().
 *
 * @property bool $enabled
 * @property string $client_id
 * @property string $client_secret
 * @property string|null $refresh_token
 * @property string|null $account_email
 * @property string|null $folder_id
 * @property string $encryption_key
 * @property Carbon|null $key_revealed_at
 * @property Carbon|null $key_saved_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_test_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_error
 */
class BackupDriveSetting extends Model
{
    /**
     * Changing which Google OAuth client is used voids the connection: the
     * refresh token belongs to the old client, so the owner connects and
     * tests again.
     */
    public const CONNECTION_FIELDS = ['client_id', 'client_secret'];

    protected $fillable = ['client_id', 'client_secret'];

    protected $hidden = ['id', 'client_secret', 'refresh_token', 'encryption_key'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'client_secret' => 'encrypted',
            'refresh_token' => 'encrypted',
            'encryption_key' => 'encrypted',
            'key_revealed_at' => 'datetime',
            'key_saved_at' => 'datetime',
            'connected_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /**
     * The row, or null when Drive was never set up. Null too before the
     * migration has run: the release deploys the code first and the owner runs
     * `migrate` afterwards, and the nightly backup, the operations centre and
     * the console page must work in between.
     */
    public static function current(): ?self
    {
        try {
            return static::query()->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** Null when the stored key no longer decrypts (APP_KEY changed). */
    public function cipher(): ?BackupCipher
    {
        try {
            $key = BackupCipher::decodeKey((string) $this->encryption_key);
        } catch (DecryptException) {
            return null;
        }

        return $key === null ? null : new BackupCipher($key);
    }

    public function hasSecret(): bool
    {
        return filled($this->getRawOriginal('client_secret'));
    }

    public function isConnected(): bool
    {
        return filled($this->getRawOriginal('refresh_token')) && filled($this->folder_id);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isKeySaved(): bool
    {
        return $this->key_saved_at !== null;
    }
}
