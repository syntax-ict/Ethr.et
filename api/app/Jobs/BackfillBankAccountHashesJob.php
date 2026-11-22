<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmployeeBankDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Fills `account_number_hash` on one organisation's bank details saved before
 * the column existed (2026-11-22), so the shared-account check before
 * approval sees every account. A save sets the hash from then on
 * (EmployeeBankDetail::booted).
 *
 * A slice at a time: the queue is drained in 25-second turns on shared
 * hosting, so the job stops at its deadline and queues itself again for the
 * rest. Each statement states `tenant_id` through the query builder, so it
 * needs no resolved tenant and adds no scope bypass. Rows are written with
 * the hash only: no event, no audit entry, no change to `updated_at`.
 *
 * A number that cannot be decrypted is given an empty hash, which matches
 * nothing, so it is not read again on every run.
 */
class BackfillBankAccountHashesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Rows read per query. */
    public const CHUNK = 200;

    /** Seconds of work per turn, inside the 25-second drain. */
    public const SECONDS = 15;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?float $deadline = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $deadline = $this->deadline ?? microtime(true) + self::SECONDS;

        if (self::fill($this->tenantId, $deadline) > 0) {
            self::dispatch($this->tenantId);
        }
    }

    /**
     * Fills the organisation's missing hashes until none is left or the
     * deadline (a `microtime(true)` value) has passed; at least one slice is
     * done per call. Returns how many are still missing.
     */
    public static function fill(int $tenantId, float $deadline): int
    {
        do {
            $rows = self::missing($tenantId)->orderBy('id')->limit(self::CHUNK)->get(['id', 'account_number']);

            foreach ($rows as $row) {
                try {
                    $hash = EmployeeBankDetail::hashAccountNumber(Crypt::decryptString((string) $row->account_number)) ?? '';
                } catch (DecryptException) {
                    $hash = '';
                }

                DB::table('employee_bank_details')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $row->id)
                    ->update(['account_number_hash' => $hash]);
            }
        } while ($rows->count() === self::CHUNK && microtime(true) < $deadline);

        return self::missing($tenantId)->count();
    }

    /** The organisation's bank details with a number and no hash yet. */
    public static function missing(int $tenantId): Builder
    {
        return DB::table('employee_bank_details')
            ->where('tenant_id', $tenantId)
            ->whereNull('account_number_hash')
            ->whereNotNull('account_number');
    }
}
