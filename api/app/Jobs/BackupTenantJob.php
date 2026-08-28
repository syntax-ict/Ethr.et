<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use App\Traits\BelongsToTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Throwable;

/**
 * Exports every tenant-scoped table's rows for one tenant to a single JSON
 * file in storage, then notifies the requesting super admin.
 *
 * This exists because AdminTenantController::backup() used to tell admins
 * "Backup job queued. You will be notified when the export is ready." without
 * ever queuing anything — a fabricated success message on a disaster-recovery
 * adjacent feature. See docs/ETHR_AUDIT_2026-08-03.md's "no fabricated
 * data/success" precedent.
 *
 * Scope: this is a data export snapshot for portability/compliance, not a
 * binary-restorable system backup — model `$hidden` attributes (password
 * hashes, MFA secrets) are excluded by `toArray()`, same as every API
 * response. Restoring a tenant from this file means re-importing rows, not
 * restoring logins as they were.
 *
 * Tenant-scoped tables are discovered the same way TenantIsolationTest does
 * (every Eloquent model using BelongsToTenant), so this stays correct as new
 * tenant-scoped models are added without needing a hardcoded table list.
 */
class BackupTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly int $tenantId,
        private readonly ?int $requestedByUserId,
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        try {
            $path = $this->export($tenant);
            $this->notifyRequester($tenant, true, $path);
        } catch (Throwable $e) {
            Log::error('Tenant backup export failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            $this->notifyRequester($tenant, false, null);
        }
    }

    private function export(Tenant $tenant): string
    {
        $payload = [
            'tenant' => [
                'public_id' => $tenant->public_id,
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
            ],
            'exported_at' => now()->toIso8601String(),
            'tables' => [],
        ];

        foreach ($this->discoverTenantScopedModels() as $class) {
            /** @var Model $instance */
            $instance = new $class;
            $table = $instance->getTable();

            $payload['tables'][$table] = $class::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->get()
                ->map(fn (Model $row) => $row->toArray())
                ->all();
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filename = sprintf('backups/%s/%s.json', $tenant->subdomain, now()->format('Y-m-d_His'));

        Storage::disk(config('filesystems.default'))->put($filename, $json);

        return $filename;
    }

    private function notifyRequester(Tenant $tenant, bool $success, ?string $path): void
    {
        if ($this->requestedByUserId === null) {
            return;
        }

        $requester = User::withoutGlobalScopes()->find($this->requestedByUserId);

        if (! $requester) {
            return;
        }

        $requester->notify(new SystemAlertNotification(
            $success ? 'Tenant backup ready' : 'Tenant backup failed',
            $success
                ? "The data export for {$tenant->name} has completed."
                : "The data export for {$tenant->name} could not be completed. Check the application logs.",
            array_filter([
                'tenant_public_id' => $tenant->public_id,
                'path' => $path,
            ]),
        ));
    }

    /**
     * @return list<class-string<Model>>
     */
    private function discoverTenantScopedModels(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                continue;
            }

            if (in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }
}
