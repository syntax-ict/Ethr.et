<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Backup\BackupCipher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Where nightly backups are copied off the host, configured by the super
 * admin in the platform console. One row, global by design
 * (TenantIsolationTest::GLOBAL_MODELS); while it exists it takes over from
 * the `.env` disk, as SmsGateway does for SMS.
 *
 * `secret_access_key` and `encryption_key` are encrypted at rest and in
 * $hidden. The key is generated here, never typed, and leaves only through
 * BackupStorageController::revealKey().
 *
 * @property bool $enabled
 * @property string $endpoint
 * @property string $region
 * @property string $bucket
 * @property string $access_key_id
 * @property string $secret_access_key
 * @property bool $path_style
 * @property string $encryption_key
 * @property Carbon|null $key_revealed_at
 * @property Carbon|null $key_saved_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_test_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_error
 */
class BackupStorageSetting extends Model
{
    /**
     * Fields that change where copies go or who may write them. Editing any of
     * them means the last test no longer proves anything, so the storage must
     * be tested again before copies can be on.
     */
    public const CONNECTION_FIELDS = [
        'endpoint', 'region', 'bucket', 'access_key_id', 'secret_access_key', 'path_style',
    ];

    protected $fillable = [
        'endpoint', 'region', 'bucket', 'access_key_id', 'secret_access_key', 'path_style',
    ];

    protected $hidden = ['id', 'secret_access_key', 'encryption_key'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'path_style' => 'boolean',
            'secret_access_key' => 'encrypted',
            'encryption_key' => 'encrypted',
            'key_revealed_at' => 'datetime',
            'key_saved_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /**
     * The row, or null when the console has never been used. Null too before
     * the migration has run, so the operations centre and the nightly backup
     * keep working on a deploy whose migrate step is still to come.
     */
    public static function current(): ?self
    {
        try {
            return static::query()->first();
        } catch (Throwable) {
            return null;
        }
    }

    public function cipher(): ?BackupCipher
    {
        $key = BackupCipher::decodeKey((string) $this->encryption_key);

        return $key === null ? null : new BackupCipher($key);
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
