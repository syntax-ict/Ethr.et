<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The legal forms an Ethiopian organisation registers as, each under the
     * statutory regime of one of the six existing types.
     *
     * A new type names its `parent_code` and owns no rule rows: the resolver
     * reads the type's rows, then its parent's, then every type's, so each new
     * type is paid exactly as its parent is until a sourced rule differs.
     * Inheritance is one level deep. `group_code` orders the picker
     * (private, non-profit, public, international).
     *
     * The Amharic names of the new rows are drafts awaiting native review
     * (docs/i18n/AMHARIC-REVIEW.md); a name moves no money. Coverage
     * questions each new type raises are in docs/payroll/ACCOUNTANT-SIGN-OFF.md.
     */
    public function up(): void
    {
        Schema::table('organization_types', function (Blueprint $table): void {
            $table->string('parent_code', 40)->nullable();
            $table->string('group_code', 20)->default('private');
        });

        foreach (self::groups() as $code => $group) {
            DB::table('organization_types')->where('code', $code)->update(['group_code' => $group]);
        }

        $now = now();

        foreach (self::types() as [$code, $parent, $group, $order, $name, $nameAm, $description]) {
            if (DB::table('organization_types')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('organization_types')->insert([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'name' => $name,
                'name_am' => $nameAm,
                'description' => $description,
                'sort_order' => $order,
                'is_active' => true,
                'parent_code' => $parent,
                'group_code' => $group,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('organization_types')->whereIn('code', array_column(self::types(), 0))->delete();

        Schema::table('organization_types', function (Blueprint $table): void {
            $table->dropColumn(['parent_code', 'group_code']);
        });
    }

    /** @return array<string, string> */
    private static function groups(): array
    {
        return [
            'private' => 'private',
            'ngo' => 'non_profit',
            'public_enterprise' => 'public',
            'government' => 'public',
            'defence_police' => 'public',
            'international_organization' => 'international',
        ];
    }

    /** @return list<array{string, string, string, int, string, string, string}> */
    private static function types(): array
    {
        return [
            ['sole_proprietor', 'private', 'private', 11, 'Sole proprietor', 'የግል ነጋዴ',
                'A business owned and run by one person (Commercial Code Proclamation No. 1243/2021).'],
            ['private_limited_company', 'private', 'private', 12, 'Private limited company (PLC)', 'ኃላፊነቱ የተወሰነ የግል ማኅበር',
                'A PLC, including a one-person PLC (Commercial Code Proclamation No. 1243/2021).'],
            ['share_company', 'private', 'private', 13, 'Share company (S.C.)', 'አክሲዮን ማኅበር',
                'A share company, including banks and insurers (Commercial Code Proclamation No. 1243/2021).'],
            ['partnership', 'private', 'private', 14, 'Partnership or joint venture', 'የሽርክና ማኅበር',
                'A general or limited partnership, or a joint venture (Commercial Code Proclamation No. 1243/2021).'],
            ['cooperative', 'private', 'private', 15, 'Cooperative society', 'የኅብረት ሥራ ማኅበር',
                'A cooperative society or union (Cooperative Societies Proclamation No. 985/2016).'],
            ['foreign_company_branch', 'private', 'private', 16, 'Branch of a foreign company', 'የውጭ ኩባንያ ቅርንጫፍ',
                'The Ethiopian branch of a business registered abroad.'],
            ['foreign_cso', 'ngo', 'non_profit', 41, 'Foreign civil society organisation', 'የውጭ ሲቪል ማኅበረሰብ ድርጅት',
                'A foreign or international NGO registered in Ethiopia (Proclamation No. 1113/2019).'],
            ['religious_organization', 'ngo', 'non_profit', 42, 'Religious organisation', 'የሃይማኖት ድርጅት',
                'A church, mosque or other religious institution, outside Proclamation No. 1113/2019.'],
            ['regional_government', 'government', 'public', 31, 'Regional or city government office', 'የክልል ወይም የከተማ መንግሥት መሥሪያ ቤት',
                'A regional state or city administration bureau, office or agency.'],
            ['diplomatic_mission', 'international_organization', 'international', 51, 'Embassy or diplomatic mission', 'ዲፕሎማቲክ ሚሲዮን',
                'An embassy, consulate or other diplomatic mission.'],
        ];
    }
};
