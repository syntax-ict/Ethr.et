<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An Afaan Oromoo name for each kind of organisation in law, beside
 * `name_am`. Until now the sign-up form, the setup wizard and the
 * organisation's settings showed an Oromo reader the English name
 * (QA 2026-11-14, O-2).
 *
 * Nullable, as the other `_om` columns are: a kind with no Oromo name falls
 * back to English. Appended, never `->after()`: Scramble builds the contract
 * from column order, and only MySQL honours the clause.
 *
 * The names are drafts written without a native speaker
 * (docs/i18n/AMHARIC-REVIEW.md). Correct one with a new migration that
 * updates the row, never by editing this one. A name moves no money.
 */
return new class extends Migration
{
    /** @var array<string, string> code => Afaan Oromoo name */
    private const NAMES = [
        'private' => 'Dhaabbata Dhuunfaa',
        'public_enterprise' => 'Dhaabbata Misooma Mootummaa',
        'government' => 'Waajjira Mootummaa (Siivil Sarviisii)',
        'ngo' => 'Dhaabbata Tola Ooltummaa ykn Waldaa',
        'international_organization' => 'Dhaabbata Idil-Addunyaa ykn Imbaasii',
        'defence_police' => 'Raayyaa Ittisaa ykn Poolisii',
        'sole_proprietor' => 'Daldalaa Dhuunfaa',
        'private_limited_company' => "Waldaa Dhuunfaa Itti Gaafatamummaan Isaa Murtaa'e (PLC)",
        'share_company' => 'Waldaa Aksiyoonaa (S.C.)',
        'partnership' => 'Waldaa Shariikummaa ykn Hojii Waloo',
        'cooperative' => 'Waldaa Hojii Gamtaa',
        'foreign_company_branch' => 'Damee Kubbaaniyyaa Biyya Alaa',
        'foreign_cso' => 'Dhaabbata Hawaasa Siivilii Biyya Alaa',
        'religious_organization' => 'Dhaabbata Amantaa',
        'regional_government' => 'Waajjira Mootummaa Naannoo ykn Magaalaa',
        'diplomatic_mission' => 'Imbaasii ykn Ergama Dippilomaasii',
    ];

    public function up(): void
    {
        Schema::table('organization_types', function (Blueprint $table) {
            $table->string('name_om')->nullable();
        });

        foreach (self::NAMES as $code => $name) {
            DB::table('organization_types')
                ->where('code', $code)
                ->update(['name_om' => $name, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('organization_types', function (Blueprint $table) {
            $table->dropColumn('name_om');
        });
    }
};
