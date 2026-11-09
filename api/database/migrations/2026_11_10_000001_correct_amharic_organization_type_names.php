<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects an Amharic organisation-type name drafted in
 * 2026_11_09_000002_add_lineage_to_organization_types, which is left as it
 * was written (docs/i18n/AMHARIC-REVIEW.md). `diplomatic_mission` read
 * ዲፕሎማቲክ ሚሲዮን, a transliteration that dropped "embassy"; its English
 * name is "Embassy or diplomatic mission". A name moves no money.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> code => [drafted, corrected] */
    private const NAMES = [
        'diplomatic_mission' => ['ዲፕሎማቲክ ሚሲዮን', 'ኤምባሲ ወይም ዲፕሎማሲያዊ ሚሲዮን'],
    ];

    public function up(): void
    {
        foreach (self::NAMES as $code => [$drafted, $corrected]) {
            DB::table('organization_types')
                ->where('code', $code)
                ->where('name_am', $drafted)
                ->update(['name_am' => $corrected, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $code => [$drafted, $corrected]) {
            DB::table('organization_types')
                ->where('code', $code)
                ->where('name_am', $corrected)
                ->update(['name_am' => $drafted, 'updated_at' => now()]);
        }
    }
};
