<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The working-days rows were written with "(ACCOUNTANT-SIGN-OFF.md)" in
     * their `legal_reference`, and Settings, Payroll rules shows that text to
     * customers as the rule's source. A file in ETHR's repository is not a
     * source a customer can read, so the parenthesis goes. What is left still
     * says the figure is an ETHR convention and not the text of a law.
     *
     * The migrations that wrote the old text have already run in production
     * and are not edited; this one rewrites the stored rows. Payroll runs keep
     * the reference they were calculated under in their own snapshot, which
     * this does not touch.
     */
    private const FILE_SUFFIX = ' (ACCOUNTANT-SIGN-OFF.md)';

    public function up(): void
    {
        DB::table('statutory_parameters')
            ->where('legal_reference', 'like', '%'.self::FILE_SUFFIX)
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::table('statutory_parameters')
                    ->where('id', $row->id)
                    ->update([
                        'legal_reference' => substr((string) $row->legal_reference, 0, -strlen(self::FILE_SUFFIX)),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Nothing to restore: the file name was never part of the rule.
    }
};
