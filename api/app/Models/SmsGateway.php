<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One HTTP SMS gateway, configured in the platform console.
 *
 * Global by design: SMS is bought once for the platform, so there is no tenant
 * to own a row (TenantIsolationTest::GLOBAL_MODELS). At most two rows, keyed by
 * `slot`: `primary` carries every number, `secondary` the numbers the route in
 * SmsSetting sends elsewhere.
 *
 * `auth_secret` is encrypted at rest and in $hidden, and no API returns it —
 * responses say only whether one is set.
 *
 * @property string $slot
 * @property string|null $label
 * @property string $endpoint
 * @property string $method
 * @property string $auth_type
 * @property string|null $auth_header
 * @property string|null $auth_username
 * @property string|null $auth_secret
 * @property string|null $username_field
 * @property string|null $password_field
 * @property string $recipient_field
 * @property string $message_field
 * @property string|null $sender_field
 * @property string|null $sender_id
 * @property string $number_format
 * @property string|null $success_json_path
 * @property string|null $success_json_value
 * @property string|null $success_body_contains
 * @property int $timeout_seconds
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_test_at
 * @property bool|null $last_test_ok
 * @property int|null $last_test_status
 * @property string|null $last_test_response
 */
class SmsGateway extends Model
{
    public const SLOT_PRIMARY = 'primary';

    public const SLOT_SECONDARY = 'secondary';

    public const SLOTS = [self::SLOT_PRIMARY, self::SLOT_SECONDARY];

    public const METHODS = ['form', 'json', 'get'];

    public const AUTH_TYPES = ['none', 'header', 'bearer', 'fields', 'basic'];

    /** +2519…, 2519…, or 09… */
    public const NUMBER_FORMATS = ['e164', 'international', 'local'];

    /**
     * Fields that change where or how a message is sent. Editing any of them
     * means the last successful test no longer proves anything, so the gateway
     * must be tested again before SMS can be on.
     */
    public const CONNECTION_FIELDS = [
        'endpoint', 'method', 'auth_type', 'auth_header', 'auth_username',
        'auth_secret', 'username_field', 'password_field', 'recipient_field',
        'message_field', 'sender_field', 'sender_id', 'number_format',
        'success_json_path', 'success_json_value', 'success_body_contains',
    ];

    protected $fillable = [
        'slot', 'label', 'endpoint', 'method', 'auth_type', 'auth_header',
        'auth_username', 'auth_secret', 'username_field', 'password_field',
        'recipient_field', 'message_field', 'sender_field', 'sender_id',
        'number_format', 'success_json_path', 'success_json_value',
        'success_body_contains', 'timeout_seconds',
    ];

    protected $hidden = ['id', 'auth_secret'];

    protected function casts(): array
    {
        return [
            'auth_secret' => 'encrypted',
            'timeout_seconds' => 'integer',
            'verified_at' => 'datetime',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
            'last_test_status' => 'integer',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasSecret(): bool
    {
        return filled($this->auth_secret);
    }
}
