<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Reference\ReferenceCatalogue;
use Illuminate\Database\Seeder;

/**
 * Re-syncs the places and banks from `database/data/reference/` after a data
 * file changes. The first load is made by the migration that creates the
 * table; this is how an edited name, a new town or a retired bank reaches a
 * database that already has the rows.
 *
 * Safe to run on every deploy: rows are matched by (kind, code), `public_id`
 * is written on insert only, and a row that left the files is deactivated, not
 * deleted.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $counts = ReferenceCatalogue::sync();

        ReferenceCatalogue::forgetCached();

        $this->command?->info(sprintf(
            'Reference data synced (%d added, %d updated, %d retired).',
            $counts['inserted'],
            $counts['updated'],
            $counts['deactivated'],
        ));
    }
}
