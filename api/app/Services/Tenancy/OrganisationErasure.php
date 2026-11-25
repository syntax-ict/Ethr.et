<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Permanent erasure of an organisation that has left ETHR.
 *
 * Deleting an organisation in the console closes it and keeps every record
 * (docs/operations/TENANT-DELETION.md). That is right while a customer might
 * come back, and wrong once they have gone: Proclamation 1321/2024 Art. 50 has
 * a processor destroy the controller's data when it is told to, and the DPA
 * promises it at the end of the contract. This is that destruction, and it is
 * deliberately not in the console: the owner runs it, through `ethr:erase-
 * organisation` or the token-guarded maintenance endpoint.
 *
 * Only an organisation that is cancelled, deleted, and has been deleted for at
 * least `tenancy.erasure_grace_days` (30) qualifies: the grace period is the
 * window the DPA gives a customer to collect its export, and the time in which
 * a deletion can still be undone.
 *
 * WHAT GOES. Every row of every table with a `tenant_id` column that belongs
 * to the organisation, its users' sessions and API tokens, and its files:
 * `tenants/{public_id}/`, `exports/{public_id}/` and `backups/{subdomain}/`.
 * The tables are found from the schema, not listed by hand, so a table added
 * later is erased without anyone remembering to add it.
 *
 * WHAT STAYS, and why (RETAINED):
 *  - `audit_log`, which refuses DELETE at database level by an owner decision
 *    (docs/AUDIT_LOG_INTEGRITY_DECISION.md). Its rows are ETHR's own record of
 *    who did what, and the erasure itself is one of them.
 *  - `invoices` and `subscriptions`, ETHR's own billing and tax records, of
 *    which ETHR is controller; they hold amounts and dates, not employees.
 *  - `tenant_subdomain_aliases`, so an address the organisation retired is
 *    never handed to someone else.
 *  - The `tenants` row itself, as a tombstone: its name becomes "Erased
 *    organisation", every other nullable column is cleared, and its address
 *    stays reserved (Tenant::slugAvailableFor() reads deleted tenants), so
 *    nobody can register it and receive mail or links meant for the old one.
 *
 * Copies inside backups are not reached: an archive is one file holding every
 * organisation. They age out with rotation, and the certificate states by
 * when (`backups_rotate_out_by`).
 *
 * Raw queries throughout, each stating the organisation's `tenant_id` itself:
 * no global scope is bypassed, because none is used.
 */
class OrganisationErasure
{
    /** Tables with a `tenant_id` that erasure keeps, and the reason given in the certificate. */
    public const RETAINED = [
        'audit_log' => 'ETHR\'s append-only record of actions, including this erasure; the table refuses DELETE',
        'invoices' => 'ETHR\'s own billing and tax record; amounts and dates, no employee data',
        'subscriptions' => 'ETHR\'s own billing record, which the invoices refer to',
        'tenant_subdomain_aliases' => 'keeps the organisation\'s retired addresses reserved',
    ];

    /** Columns of the tombstone that are kept; every other nullable column is cleared. */
    private const TOMBSTONE_KEEPS = [
        'id', 'public_id', 'name', 'subdomain', 'status', 'type', 'organization_type',
        'trial_ends_at', 'created_at', 'updated_at', 'deleted_at',
        'terms_accepted_version', 'terms_accepted_at',
        'erased_at', 'erased_by', 'erasure_certificate',
    ];

    public const ERASED_NAME = 'Erased organisation';

    /** Password-reset and activation tokens: keyed by tenant and email, with no `tenant_id` column. */
    private const TOKEN_TABLES = ['password_reset_tokens', 'account_activation_tokens'];

    /** The deleted organisation with this address, or null. */
    public function find(string $subdomain): ?Tenant
    {
        return Tenant::onlyTrashed()->where('subdomain', $subdomain)->first();
    }

    /**
     * Why this organisation cannot be erased now, as a code, or null when it can.
     *
     * @return 'not_deleted'|'not_cancelled'|'in_grace_period'|'already_erased'|null
     */
    public function refusal(Tenant $tenant): ?string
    {
        return match (true) {
            $tenant->erased_at !== null => 'already_erased',
            ! $tenant->trashed() => 'not_deleted',
            $tenant->status !== TenantStatus::CANCELLED => 'not_cancelled',
            $tenant->deleted_at->greaterThan($this->graceCutoff()) => 'in_grace_period',
            default => null,
        };
    }

    /** The first moment this organisation can be erased. */
    public function eligibleFrom(Tenant $tenant): ?CarbonInterface
    {
        return $tenant->deleted_at?->copy()->addDays($this->graceDays());
    }

    /**
     * What erasure would remove, without removing anything.
     *
     * @return array{rows: array<string, int>, user_rows: array<string, int>, files: int, retained: array<string, int>}
     */
    public function inventory(Tenant $tenant): array
    {
        $rows = [];
        foreach ($this->tenantTables() as $table) {
            $count = DB::table($table)->where('tenant_id', $tenant->id)->count();
            if ($count > 0) {
                $rows[$table] = $count;
            }
        }

        $retained = [];
        foreach (array_keys(self::RETAINED) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                $retained[$table] = DB::table($table)->where('tenant_id', $tenant->id)->count();
            }
        }

        return [
            'rows' => $rows,
            'user_rows' => $this->userRowCounts($tenant),
            'files' => count($this->files($tenant)),
            'retained' => $retained,
        ];
    }

    /**
     * Erase the organisation. Refuses unless refusal() is null.
     *
     * @return array<string, mixed> the erasure certificate
     */
    public function erase(Tenant $tenant, ?int $erasedBy = null, string $via = 'command'): array
    {
        $refusal = $this->refusal($tenant);
        if ($refusal !== null) {
            throw new RuntimeException("This organisation cannot be erased: {$refusal}.");
        }

        $before = $this->inventory($tenant);
        $files = $this->files($tenant);
        $nameBefore = $tenant->name;
        $userIds = DB::table('users')->where('tenant_id', $tenant->id)->pluck('id')->all();

        // Off, then the transaction: SQLite ignores the switch inside one. The
        // tables are also deleted children first, so a foreign key between two
        // of them never decides the outcome where the switch is not honoured.
        Schema::withoutForeignKeyConstraints(function () use ($tenant, $userIds): void {
            DB::transaction(function () use ($tenant, $userIds): void {
                foreach (array_chunk($userIds, 500) as $chunk) {
                    DB::table('personal_access_tokens')
                        ->where('tokenable_type', (new User)->getMorphClass())
                        ->whereIn('tokenable_id', $chunk)
                        ->delete();
                    DB::table('sessions')->whereIn('user_id', $chunk)->delete();
                    DB::table('trusted_devices')->whereIn('user_id', $chunk)->delete();
                    DB::table('otp_codes')->whereIn('user_id', $chunk)->delete();
                }

                // Keyed `{tenant_id}|{email}` since 2026-10-31, so another
                // organisation's token for the same address is not touched.
                foreach (self::TOKEN_TABLES as $table) {
                    DB::table($table)->where('email', 'like', $tenant->id.'|%')->delete();
                }

                if (DB::connection()->getDriverName() === 'sqlite') {
                    DB::statement('PRAGMA defer_foreign_keys = ON');
                }

                $plan = $this->deletionPlan($this->tenantTables());
                foreach ($plan['clear'] as [$table, $column]) {
                    DB::table($table)->where('tenant_id', $tenant->id)->update([$column => null]);
                }
                foreach ($plan['order'] as $table) {
                    DB::table($table)->where('tenant_id', $tenant->id)->delete();
                }
            });
        });

        $disk = Storage::disk(config('filesystems.default'));
        foreach ($this->directories($tenant) as $directory) {
            $disk->deleteDirectory($directory);
        }

        // Before the tombstone clears the custom domain the cache is keyed by.
        $tenant->forgetResolverCache();

        $now = now();
        $certificate = [
            'organisation' => $tenant->public_id,
            'address' => $tenant->subdomain,
            'name_before_erasure' => $nameBefore,
            'deleted_at' => $tenant->deleted_at?->toIso8601String(),
            'erased_at' => $now->toIso8601String(),
            'erased_via' => $via,
            'rows_erased' => $before['rows'],
            'user_rows_erased' => $before['user_rows'],
            'files_erased' => count($files),
            'retained' => collect($before['retained'])
                ->map(fn (int $count, string $table): array => ['rows' => $count, 'reason' => self::RETAINED[$table]])
                ->all(),
            'backups_rotate_out_by' => $now->copy()->addDays($this->backupRetentionDays())->toDateString(),
        ];

        $this->tombstone($tenant, $erasedBy, $now, $certificate);

        AuditLog::record('admin.tenant.erased', $tenant, [
            'name_before_erasure' => $nameBefore,
            'rows_erased' => array_sum($before['rows']),
            'files_erased' => count($files),
            'audit_rows_retained' => $before['retained']['audit_log'] ?? 0,
            'backups_rotate_out_by' => $certificate['backups_rotate_out_by'],
            'via' => $via,
        ]);

        return $certificate;
    }

    /**
     * Every table holding the organisation's rows, except those kept.
     *
     * @return list<string>
     */
    public function tenantTables(): array
    {
        $tables = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table === 'tenants' || array_key_exists($table, self::RETAINED)) {
                continue;
            }
            if (Schema::hasColumn($table, 'tenant_id')) {
                $tables[] = $table;
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * The order to delete the tables in, children before parents, and the
     * links to clear first so that order exists.
     *
     * Most tables form a tree, and a child goes before its parent so neither
     * a RESTRICT nor a CASCADE between two of them is reached. A few refer to
     * each other (a department's head is an employee, an employee belongs to
     * a department; an account is an employee's and an employee has an
     * account): such a cycle is broken by clearing its nullable link, which
     * is the organisation's own data and is deleted a moment later anyway. A
     * cycle with no nullable link would fall back to name order, covered by
     * the foreign-key switch on MariaDB and deferred checks on SQLite.
     *
     * @param  list<string>  $tables
     * @return array{clear: list<array{0: string, 1: string}>, order: list<string>}
     */
    private function deletionPlan(array $tables): array
    {
        $set = array_flip($tables);
        $edges = [];
        foreach ($tables as $table) {
            $nullable = collect(Schema::getColumns($table))->pluck('nullable', 'name')->all();
            foreach (Schema::getForeignKeys($table) as $fk) {
                $parent = $fk['foreign_table'];
                if ($parent === $table || ! isset($set[$parent])) {
                    continue;
                }
                $column = count($fk['columns']) === 1 ? $fk['columns'][0] : null;
                $edges[] = [
                    'child' => $table,
                    'parent' => $parent,
                    'column' => $column,
                    'nullable' => $column !== null && ($nullable[$column] ?? false),
                ];
            }
        }

        $remaining = $tables;
        $order = [];
        $clear = [];
        while ($remaining !== []) {
            $left = array_flip($remaining);
            $live = array_filter($edges, fn (array $e): bool => isset($left[$e['child']], $left[$e['parent']]));
            $referenced = array_flip(array_column($live, 'parent'));

            $free = array_values(array_filter($remaining, fn (string $t): bool => ! isset($referenced[$t])));

            if ($free === []) {
                $breakable = array_filter($live, fn (array $e): bool => $e['nullable']);
                if ($breakable === []) {
                    array_push($order, ...$remaining);
                    break;
                }
                foreach ($breakable as $key => $edge) {
                    $clear[] = [$edge['child'], (string) $edge['column']];
                    unset($edges[$key]);
                }

                continue;
            }

            array_push($order, ...$free);
            $remaining = array_values(array_diff($remaining, $free));
        }

        return ['clear' => array_values(array_unique($clear, SORT_REGULAR)), 'order' => $order];
    }

    /** @return array<string, int> */
    private function userRowCounts(Tenant $tenant): array
    {
        $userIds = DB::table('users')->where('tenant_id', $tenant->id)->pluck('id')->all();
        $counts = $userIds === [] ? [] : [
            'personal_access_tokens' => DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $userIds)->count(),
            'sessions' => DB::table('sessions')->whereIn('user_id', $userIds)->count(),
            'trusted_devices' => DB::table('trusted_devices')->whereIn('user_id', $userIds)->count(),
            'otp_codes' => DB::table('otp_codes')->whereIn('user_id', $userIds)->count(),
        ];

        foreach (self::TOKEN_TABLES as $table) {
            $counts[$table] = DB::table($table)->where('email', 'like', $tenant->id.'|%')->count();
        }

        return array_filter($counts);
    }

    /** @return list<string> */
    private function directories(Tenant $tenant): array
    {
        return [
            'tenants/'.$tenant->public_id,
            'exports/'.$tenant->public_id,
            'backups/'.$tenant->subdomain,
        ];
    }

    /** @return list<string> */
    private function files(Tenant $tenant): array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $files = [];
        foreach ($this->directories($tenant) as $directory) {
            array_push($files, ...$disk->allFiles($directory));
        }

        return $files;
    }

    /** @param  array<string, mixed>  $certificate */
    private function tombstone(Tenant $tenant, ?int $erasedBy, Carbon $now, array $certificate): void
    {
        $clear = [];
        foreach (Schema::getColumns('tenants') as $column) {
            if ($column['nullable'] && ! in_array($column['name'], self::TOMBSTONE_KEEPS, true)) {
                $clear[$column['name']] = null;
            }
        }

        DB::table('tenants')->where('id', $tenant->id)->update(array_merge($clear, [
            'name' => self::ERASED_NAME,
            'erased_at' => $now,
            'erased_by' => $erasedBy,
            'erasure_certificate' => json_encode($certificate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]));

        $tenant->refresh();
    }

    private function graceDays(): int
    {
        return max(0, (int) config('tenancy.erasure_grace_days', 30));
    }

    private function graceCutoff(): Carbon
    {
        return now()->subDays($this->graceDays());
    }

    /** The longest any backup copy is kept: on the host, or off it. */
    private function backupRetentionDays(): int
    {
        return max((int) config('backup.keep', 2), (int) config('backup.off_host_keep', 14));
    }
}
