<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AttendanceRecord;
use App\Models\EmployeeDocument;
use App\Models\KioskSession;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\OtpCode;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\TenantExportFinishedNotification;
use App\Services\CurrentTenant;
use App\Traits\BelongsToTenant;
use App\Traits\SendsNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PharData;
use ReflectionClass;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds an organisation's export of its own data as one archive (blueprint
 * assessment A12, P12):
 *
 * - `data/<table>.jsonl`: every row of every organisation-owned table, one
 *   JSON object per line, deleted rows included. Tables are found the way
 *   TenantIsolationTest finds them, by the BelongsToTenant trait, so a table
 *   added later is exported without anyone remembering to add it here.
 * - `files/documents/`: the employee documents HR uploaded.
 * - `README.txt`: what is in the archive and what is not.
 *
 * Left out on purpose: credentials and their hashes, anything stored
 * encrypted (TIN, national ID, bank account numbers, integration secrets),
 * attendance selfies, which are biometric data kept only for their short
 * review window, and four tables that hold nothing but sign-in state or
 * import scratch rows.
 *
 * Runs with the organisation set as the current tenant and reads through
 * ordinary tenant-scoped queries, so it needs no tenant-scope bypass.
 *
 * The archive is a ZIP where the zip extension exists and a .tar.gz where
 * only phar does (the host is guaranteed one of the two, GATE-0-RESULT
 * G0-E). Files are added by path, never read into memory, because the host
 * runs PHP with 128 MB.
 */
class ExportTenantDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsNotifications, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    private const CHUNK = 500;

    /** Tables that hold only sign-in state or import scratch rows. */
    private const SKIPPED = [
        KioskSession::class,
        MigrationBatch::class,
        MigrationStagingRow::class,
        OtpCode::class,
        TrustedDevice::class,
    ];

    /**
     * Columns never exported from any table, whether or not the model hides
     * them. ApiKey's hash, for one, is not in its $hidden.
     */
    private const SECRET_COLUMNS = [
        'admin_pin',
        'auth_secret',
        'device_hash',
        'key_hash',
        'kiosk_pin',
        'mfa_recovery_codes',
        'mfa_secret',
        'national_id_hash',
        'offline_private_key',
        'offline_token',
        'password',
        'remember_token',
        'secret',
        'token',
        'webhook_token',
    ];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $exportId,
    ) {
        $this->onQueue('exports');
    }

    public function handle(CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }
        $currentTenant->set($tenant);

        $export = TenantExport::query()->where('tenant_id', $tenant->id)->find($this->exportId);
        if (! $export instanceof TenantExport || $export->status !== TenantExport::QUEUED) {
            return;
        }
        $export->update(['status' => TenantExport::RUNNING]);

        // The queue is drained inside a web request on the host, where PHP
        // stops a script after 30 seconds unless told otherwise.
        @set_time_limit(0);

        $work = storage_path('app/tmp/export-'.$export->public_id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work.'/data');

        try {
            $disk = Storage::disk(config('filesystems.default'));
            [$path, $files] = $this->build($tenant, $export, $work, $disk);

            $export->update([
                'status' => TenantExport::READY,
                'path' => $path,
                'size_bytes' => $disk->size($path),
                'file_count' => $files,
                'expires_at' => now()->addDays(TenantExport::EXPIRES_DAYS),
            ]);
        } catch (Throwable $e) {
            Log::error('Tenant data export failed', ['tenant_id' => $tenant->id, 'export' => $export->public_id, 'error' => $e->getMessage()]);
            $export->update(['status' => TenantExport::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
        } finally {
            File::deleteDirectory($work);
        }

        $requester = $export->requested_by !== null
            ? User::query()->where('tenant_id', $tenant->id)->find($export->requested_by)
            : null;
        if ($requester instanceof User) {
            $this->notify($requester, new TenantExportFinishedNotification($export->refresh()));
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ExportTenantDataJob failed', ['tenant_id' => $this->tenantId, 'error' => $exception->getMessage()]);
    }

    /** @return array{0: string, 1: int} where the archive is on the disk, and how many files it holds */
    private function build(Tenant $tenant, TenantExport $export, string $work, Filesystem $disk): array
    {
        $entries = [];
        $tables = [];

        foreach ($this->tenantModels() as $class) {
            $instance = new $class;
            $table = $instance->getTable();
            $file = $work.'/data/'.$table.'.jsonl';
            $handle = fopen($file, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Could not write '.$table.'.');
            }

            // Deleted rows included: only the soft-delete scope is removed,
            // the tenant scope stays.
            $query = $instance->newQueryWithoutScope(SoftDeletingScope::class);

            $rows = 0;
            $query->orderBy($instance->getKeyName())->chunk(self::CHUNK, function ($chunk) use ($handle, &$rows): void {
                foreach ($chunk as $row) {
                    fwrite($handle, json_encode($this->row($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                    $rows++;
                }
            });
            fclose($handle);

            $entries['data/'.$table.'.jsonl'] = $file;
            $tables[$table] = $rows;
        }

        $documents = 0;
        EmployeeDocument::query()->withTrashed()->orderBy('id')->chunk(self::CHUNK, function ($chunk) use ($disk, $work, &$entries, &$documents): void {
            foreach ($chunk as $document) {
                $stored = (string) $document->file_path;
                if ($stored === '' || ! $disk->exists($stored)) {
                    continue;
                }
                $entries['files/documents/'.$document->public_id.'-'.basename($stored)] = $this->localCopy($disk, $stored, $work);
                $documents++;
            }
        });

        file_put_contents($work.'/README.txt', $this->readme($tenant, $tables, $documents));
        $entries['README.txt'] = $work.'/README.txt';

        $archive = $this->archive($entries, $work.'/export');
        $path = 'exports/'.$tenant->public_id.'/'.$export->public_id.(str_ends_with($archive, '.tar.gz') ? '.tar.gz' : '.zip');

        $stream = fopen($archive, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not read the finished archive.');
        }
        try {
            $disk->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [$path, count($entries)];
    }

    /**
     * A file on the disk as a local path the archiver can read. On the local
     * disk that is the file itself; anywhere else it is streamed to the work
     * directory first.
     */
    private function localCopy(Filesystem $disk, string $stored, string $work): string
    {
        $local = $disk->path($stored);
        if (is_file($local)) {
            return $local;
        }

        $copy = $work.'/remote-'.md5($stored);
        $in = $disk->readStream($stored);
        $out = fopen($copy, 'wb');
        if ($in === null || $out === false) {
            throw new RuntimeException('Could not read a stored document.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $copy;
    }

    /**
     * @param  array<string, string>  $entries  name in the archive => local path
     * @return string the archive's local path
     */
    private function archive(array $entries, string $base): string
    {
        if (class_exists(ZipArchive::class)) {
            $target = $base.'.zip';
            $zip = new ZipArchive;
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the archive.');
            }
            foreach ($entries as $name => $local) {
                $zip->addFile($local, $name);
            }
            if (! $zip->close()) {
                throw new RuntimeException('Could not finish the archive.');
            }

            return $target;
        }

        if (class_exists(PharData::class)) {
            $tar = new PharData($base.'.tar');
            foreach ($entries as $name => $local) {
                $tar->addFile($local, $name);
            }
            $tar->compress(\Phar::GZ);
            unset($tar);
            @unlink($base.'.tar');

            return $base.'.tar.gz';
        }

        throw new RuntimeException('Neither the zip nor the phar extension is available, so the export cannot be packed.');
    }

    /**
     * One row: hidden attributes left out except internal ids, which are kept
     * so rows can be joined to each other, and nothing secret or encrypted.
     *
     * @return array<string, mixed>
     */
    private function row(Model $row): array
    {
        $data = $row->attributesToArray();
        $casts = $row->getCasts();

        foreach (array_keys($row->getAttributes()) as $key) {
            if (! array_key_exists($key, $data) && ($key === 'id' || str_ends_with($key, '_id'))) {
                $data[$key] = $row->getAttributes()[$key];
            }
        }

        foreach (array_keys($data) as $key) {
            if ($key === 'tenant_id'
                || in_array($key, self::SECRET_COLUMNS, true)
                || str_starts_with((string) ($casts[$key] ?? ''), 'encrypted')) {
                unset($data[$key]);
            }
        }

        if ($row instanceof AttendanceRecord) {
            unset($data['photo_path']);
        }

        return $data;
    }

    /** @param array<string, int> $tables */
    private function readme(Tenant $tenant, array $tables, int $documents): string
    {
        $lines = [
            'Data export for '.$tenant->name,
            'Created '.now()->toIso8601String().' (UTC)',
            '',
            'data/<table>.jsonl',
            '  One JSON object per line for every row the organisation owns, deleted rows',
            '  included. Internal ids are kept so rows can be joined to each other.',
            '  Amounts ending in _cents are in cents: 100 cents is 1 birr.',
            '  Times are UTC; Ethiopia is UTC+3 all year.',
            'files/documents/',
            '  The employee documents uploaded to ETHR, '.$documents.' in all. Each name',
            '  starts with the public_id of its row in data/employee_documents.jsonl.',
            '',
            'Not included: passwords, two-factor secrets, sign-in codes and tokens, keys',
            'and their hashes, anything stored encrypted (TIN, national ID, bank account',
            'numbers, integration secrets) and attendance photos.',
            '',
            'Rows per table:',
        ];
        foreach ($tables as $table => $rows) {
            $lines[] = sprintf('  %-40s %d', $table, $rows);
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<class-string<Model>> */
    private function tenantModels(): array
    {
        $models = [];
        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isSubclassOf(Model::class)
                && ! $reflection->isAbstract()
                && in_array(BelongsToTenant::class, class_uses_recursive($class), true)
                && ! in_array($class, [...self::SKIPPED, TenantExport::class], true)) {
                $models[] = $class;
            }
        }
        sort($models);

        return $models;
    }
}
