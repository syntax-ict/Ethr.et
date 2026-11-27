<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Exceptions\ErasureIncomplete;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

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
 * later is erased without anyone remembering to add it. So are the rows of a
 * table with no `tenant_id` that belong to the organisation through a parent
 * (a custom role's permissions): the foreign keys are switched off for the
 * deletion, so what their ON DELETE would have done is done by hand.
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
 * when (`backups_rotate_out_by`). Nor are queued or failed jobs (`jobs`,
 * `failed_jobs`): a payload names models by id, and a job that runs after the
 * erasure finds nothing for its tenant, but a failed job's stored exception
 * text can quote a row. docs/operations/TENANT-DELETION.md lists this as a
 * gap, not a control.
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

    /** Sign-in data keyed by user, erased with the organisation's users and counted apart. */
    private const USER_TABLES = ['personal_access_tokens', 'sessions', 'trusted_devices', 'otp_codes'];

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
     * An organisation whose records were erased but whose files could not all
     * be removed is not refused: erasing it again removes what is left.
     *
     * @return 'not_deleted'|'not_cancelled'|'in_grace_period'|'already_erased'|null
     */
    public function refusal(Tenant $tenant): ?string
    {
        return match (true) {
            $tenant->erased_at !== null && ! $this->filesPending($tenant) => 'already_erased',
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
        $tables = $this->tenantTables();
        foreach ($tables as $table) {
            $count = DB::table($table)->where('tenant_id', $tenant->id)->count();
            if ($count > 0) {
                $rows[$table] = $count;
            }
        }
        foreach ($this->dependents($tables) as $dependent) {
            if ($dependent['action'] !== 'cascade') {
                continue;
            }
            $parents = DB::table($dependent['parent'])->where('tenant_id', $tenant->id)->select($dependent['parent_column']);
            $count = DB::table($dependent['table'])->whereIn($dependent['column'], $parents)->count();
            if ($count > 0) {
                $rows[$dependent['table']] = ($rows[$dependent['table']] ?? 0) + $count;
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
     * The records, the tombstone and the audit row are one transaction, which
     * re-reads the tenant row under a lock: a second erasure that loaded the
     * organisation before the first finished is refused there, rather than
     * run again over nothing and overwrite the certificate. The files are
     * removed after it commits, because a disk cannot roll back. If that
     * fails, ErasureIncomplete says so, the certificate records it
     * (`files_pending`), and erasing again removes what is left.
     *
     * @return array<string, mixed> the erasure certificate
     *
     * @throws ErasureIncomplete when the records are erased and some files are not
     */
    public function erase(Tenant $tenant, ?int $erasedBy = null, string $via = 'command'): array
    {
        $refusal = $this->refusal($tenant);
        if ($refusal !== null) {
            throw new RuntimeException("This organisation cannot be erased: {$refusal}.");
        }

        if ($tenant->erased_at !== null) {
            return $this->removeFiles($tenant);
        }

        $before = $this->inventory($tenant);
        $files = $this->files($tenant);
        $nameBefore = $tenant->name;
        $userIds = DB::table('users')->where('tenant_id', $tenant->id)->pluck('id')->all();

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
            'files_pending' => true,
            'retained' => collect($before['retained'])
                ->map(fn (int $count, string $table): array => ['rows' => $count, 'reason' => self::RETAINED[$table]])
                ->all(),
            'backups_rotate_out_by' => $now->copy()->addDays($this->backupRetentionDays())->toDateString(),
        ];

        // Off, then the transaction: SQLite ignores the switch inside one. The
        // tables are also deleted children first, so a foreign key between two
        // of them never decides the outcome where the switch is not honoured.
        Schema::withoutForeignKeyConstraints(function () use ($tenant, $userIds, $erasedBy, $now, $certificate, $nameBefore, $before, $files, $via): void {
            DB::transaction(function () use ($tenant, $userIds, $erasedBy, $now, $certificate, $nameBefore, $before, $files, $via): void {
                $erasedAt = DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->value('erased_at');
                if ($erasedAt !== null) {
                    throw new RuntimeException('This organisation cannot be erased: already_erased.');
                }

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

                // Rows with no `tenant_id` of their own, which belong to the
                // organisation through a parent: what the foreign key would
                // have done to them, had the switch above not turned it off.
                foreach ($this->dependents($this->tenantTables()) as $dependent) {
                    $parents = DB::table($dependent['parent'])->where('tenant_id', $tenant->id)->select($dependent['parent_column']);
                    $rows = DB::table($dependent['table'])->whereIn($dependent['column'], $parents);
                    $dependent['action'] === 'cascade'
                        ? $rows->delete()
                        : $rows->update([$dependent['column'] => null]);
                }

                $plan = $this->deletionPlan($this->tenantTables());
                foreach ($plan['clear'] as [$table, $column]) {
                    DB::table($table)->where('tenant_id', $tenant->id)->update([$column => null]);
                }
                foreach ($plan['order'] as $table) {
                    DB::table($table)->where('tenant_id', $tenant->id)->delete();
                }

                $this->tombstone($tenant, $erasedBy, $now, $certificate);

                AuditLog::record('admin.tenant.erased', $tenant, [
                    'name_before_erasure' => $nameBefore,
                    'rows_erased' => array_sum($before['rows']),
                    'files_erased' => count($files),
                    'audit_rows_retained' => $before['retained']['audit_log'] ?? 0,
                    'backups_rotate_out_by' => $certificate['backups_rotate_out_by'],
                    'via' => $via,
                ]);
            });
        });

        // The model still holds the custom domain the cache is keyed by; the
        // tombstone has cleared it in the database.
        $tenant->forgetResolverCache();
        $tenant->refresh();

        return $this->removeFiles($tenant);
    }

    /**
     * Remove the organisation's files, and mark the certificate complete.
     *
     * @return array<string, mixed> the erasure certificate
     *
     * @throws ErasureIncomplete
     */
    private function removeFiles(Tenant $tenant): array
    {
        $disk = Storage::disk(config('filesystems.default'));

        try {
            foreach ($this->directories($tenant) as $directory) {
                if (! $disk->deleteDirectory($directory) && $disk->allFiles($directory) !== []) {
                    throw new RuntimeException("{$directory} could not be deleted.");
                }
            }
        } catch (Throwable $e) {
            throw new ErasureIncomplete($tenant->subdomain, $e);
        }

        $certificate = $tenant->erasure_certificate ?? [];
        unset($certificate['files_pending']);

        DB::table('tenants')->where('id', $tenant->id)->update([
            'erasure_certificate' => json_encode($certificate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $tenant->refresh();

        return $certificate;
    }

    /**
     * The tables of this connection's own database.
     *
     * Not every schema the account can see: on MariaDB the unqualified
     * listing spans every database the user may read, which names a table
     * twice when a second database has it too, and names tables this one
     * does not have.
     *
     * @return list<string>
     */
    private function tableNames(): array
    {
        return array_values(array_unique(
            Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false),
        ));
    }

    private function filesPending(Tenant $tenant): bool
    {
        return (bool) ($tenant->erasure_certificate['files_pending'] ?? false);
    }

    /**
     * Single-column foreign keys from a table with no `tenant_id` to one of
     * $tables, with what deleting the parent row would do to the child.
     *
     * A table that holds an organisation's rows only through a parent (a
     * custom role's permissions) is found from the schema like every other,
     * so a table added later is not missed. A RESTRICT or NO ACTION key is
     * left alone: the database would have refused the deletion, and a table
     * like that has to be added here on purpose.
     *
     * @param  list<string>  $tables
     * @return list<array{table: string, column: string, parent: string, parent_column: string, action: 'cascade'|'set null'}>
     */
    private function dependents(array $tables): array
    {
        $set = array_flip($tables);
        $dependents = [];
        foreach ($this->tableNames() as $table) {
            if (isset($set[$table]) || $table === 'tenants' || array_key_exists($table, self::RETAINED)
                || in_array($table, self::USER_TABLES, true) || Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $fk) {
                $action = strtolower((string) $fk['on_delete']);
                if (! isset($set[$fk['foreign_table']]) || count($fk['columns']) !== 1
                    || ! in_array($action, ['cascade', 'set null'], true)) {
                    continue;
                }
                $dependents[] = [
                    'table' => $table,
                    'column' => $fk['columns'][0],
                    'parent' => $fk['foreign_table'],
                    'parent_column' => $fk['foreign_columns'][0],
                    'action' => $action,
                ];
            }
        }

        return $dependents;
    }

    /**
     * Every table holding the organisation's rows, except those kept.
     *
     * @return list<string>
     */
    public function tenantTables(): array
    {
        $tables = [];
        foreach ($this->tableNames() as $table) {
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
