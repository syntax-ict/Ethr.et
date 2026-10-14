<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message a gateway accepted — the ledger the platform's daily SMS cap
 * counts, kept in the database rather than the cache so a cache flush cannot
 * reset what the platform has already paid for.
 *
 * It records which gateway and why, never the number or the text. Global by
 * design (TenantIsolationTest::GLOBAL_MODELS): the cap is platform-wide.
 *
 * @property string $slot
 * @property string $purpose
 */
class SmsSend extends Model
{
    public const UPDATED_AT = null;

    public const PURPOSE_MESSAGE = 'message';

    public const PURPOSE_TEST = 'test';

    protected $fillable = ['slot', 'purpose'];

    protected $hidden = ['id'];

    /** Messages accepted since midnight in Addis Ababa, where the day is counted. */
    public static function countToday(): int
    {
        $start = now('Africa/Addis_Ababa')->startOfDay()->utc();

        return static::query()->where('created_at', '>=', $start)->count();
    }
}
