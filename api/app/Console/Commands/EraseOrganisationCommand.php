<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ErasureIncomplete;
use App\Services\Tenancy\OrganisationErasure;
use Illuminate\Console\Command;
use Throwable;

/**
 * Permanently erase an organisation that left ETHR at least 30 days ago.
 *
 * Without --execute it only says what would go, which is the default on
 * purpose: erasure cannot be undone except by restoring a backup of every
 * organisation. With --execute the address must be typed again in --confirm,
 * the RestoreBackupRequest pattern: a command line recalled from history with
 * a different address in it is refused, not run.
 *
 * Production has no shell, so the owner normally runs this through
 * `POST /api/v1/maintenance/erase-organisation` (docs/operations/TENANT-DELETION.md).
 */
class EraseOrganisationCommand extends Command
{
    protected $signature = 'ethr:erase-organisation
        {address : The deleted organisation\'s address (its subdomain)}
        {--execute : Erase it. Without this, only show what would be erased}
        {--confirm= : The address again, required with --execute}';

    protected $description = 'Erase a deleted organisation\'s data permanently, after its grace period';

    public function handle(OrganisationErasure $erasure): int
    {
        $address = (string) $this->argument('address');
        $tenant = $erasure->find($address);

        if ($tenant === null) {
            $this->error("No deleted organisation has the address {$address}. Delete it in the platform console first.");

            return self::FAILURE;
        }

        $refusal = $erasure->refusal($tenant);
        if ($refusal !== null) {
            $this->error(match ($refusal) {
                'already_erased' => "{$address} was already erased on {$tenant->erased_at?->toDateString()}.",
                'not_cancelled' => "{$address} is not cancelled.",
                'in_grace_period' => "{$address} was deleted on {$tenant->deleted_at?->toDateString()} and can be erased from {$erasure->eligibleFrom($tenant)?->toDateString()}.",
                default => "{$address} cannot be erased ({$refusal}).",
            });

            return self::FAILURE;
        }

        $inventory = $erasure->inventory($tenant);

        $this->line("  organisation  {$tenant->name} ({$tenant->public_id})");
        $this->line('  deleted       '.$tenant->deleted_at?->toDateString());
        $this->line('  rows          '.array_sum($inventory['rows']).' in '.count($inventory['rows']).' tables');
        $this->line('  sign-in data  '.array_sum($inventory['user_rows']).' rows');
        $this->line('  files         '.$inventory['files']);
        foreach ($inventory['retained'] as $table => $count) {
            $this->line("  kept          {$table}: {$count} (".OrganisationErasure::RETAINED[$table].')');
        }

        if (! $this->option('execute')) {
            $this->info('Nothing was erased. Run again with --execute --confirm='.$address.' to erase it.');

            return self::SUCCESS;
        }

        if ($this->option('confirm') !== $address) {
            $this->error('--confirm must repeat the address exactly. Nothing was erased.');

            return self::FAILURE;
        }

        try {
            $certificate = $erasure->erase($tenant, via: 'command');
        } catch (ErasureIncomplete $e) {
            $this->error('Erasure INCOMPLETE: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Erasure FAILED, and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Erased {$address}. The certificate to send the organisation:");
        $this->line((string) json_encode($certificate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
