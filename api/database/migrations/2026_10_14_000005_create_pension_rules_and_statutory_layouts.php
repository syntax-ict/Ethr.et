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
     * Statutory rules as dated data, so a change in the law is a new row and
     * not a deploy (docs/payroll/STATUTORY-RULES-RESEARCH.md).
     *
     * - `pension_rules`: rates per scheme, effective dates and the law they
     *   come from. Platform rows (tenant_id NULL) are seeded here from the
     *   research; an organisation may add its own, which then replace the
     *   platform row for its scheme — the same arrangement as tax_brackets.
     * - `tax_brackets.legal_reference`: which law a ladder implements, so a
     *   payslip and a schedule can say so. The ladders' values and dates are
     *   not touched.
     * - `statutory_layouts`: the agency names and column layouts of the
     *   monthly schedules, dated, platform-wide.
     */
    public function up(): void
    {
        Schema::create('pension_rules', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scheme', 30);
            $table->decimal('employee_rate', 5, 2);
            $table->decimal('employer_rate', 5, 2);
            $table->unsignedBigInteger('ceiling_cents')->nullable();
            $table->unsignedBigInteger('floor_cents')->nullable();
            $table->string('base', 30)->default('basic');
            $table->boolean('exempt_non_nationals')->default(true);
            $table->unsignedSmallInteger('min_service_days')->nullable();
            $table->string('legal_reference', 200);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'scheme', 'effective_from'], 'pension_rules_tenant_scheme_from_index');
        });

        Schema::create('statutory_layouts', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('schedule', 20);
            $table->string('scheme', 30)->nullable();
            $table->string('agency', 200);
            $table->string('title', 200);
            $table->json('columns');
            $table->string('note', 500)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['schedule', 'scheme', 'effective_from'], 'statutory_layouts_schedule_scheme_from_index');
        });

        Schema::table('tax_brackets', function (Blueprint $table): void {
            $table->string('legal_reference', 200)->nullable();
        });

        $now = now();

        // P1–P4 of the research: 1268/2022 Art. 10 and 1267/2022 Arts. 10–11,
        // both in force 18 March 2022. No ceiling (P6); base basic (P7, open);
        // non-nationals exempt (P8); no 45-day rule applied (P10, open).
        $rules = [
            ['private', 7, 11, 'Proclamation No. 1268/2022, Art. 10'],
            ['public', 7, 11, 'Proclamation No. 1267/2022, Art. 10'],
            ['public_military', 7, 33, 'Proclamation No. 1267/2022, Art. 11'],
        ];
        foreach ($rules as [$scheme, $employee, $employer, $reference]) {
            DB::table('pension_rules')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => null,
                'scheme' => $scheme,
                'employee_rate' => $employee,
                'employer_rate' => $employer,
                'ceiling_cents' => null,
                'floor_cents' => null,
                'base' => 'basic',
                'exempt_non_nationals' => true,
                'min_service_days' => null,
                'legal_reference' => $reference,
                'effective_from' => '2022-03-18',
                'effective_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('tax_brackets')->whereNull('tenant_id')->where('effective_from', '<', '2025-07-07')
            ->update(['legal_reference' => 'Income Tax Proclamation No. 979/2016']);
        DB::table('tax_brackets')->whereNull('tenant_id')->where('effective_from', '>=', '2025-07-07')
            ->update(['legal_reference' => 'Income Tax (Amendment) Proclamation No. 1395/2025']);

        foreach (self::layouts() as $layout) {
            DB::table('statutory_layouts')->insert([
                'public_id' => (string) Str::ulid(),
                ...$layout,
                'columns' => json_encode($layout['columns']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('tax_brackets', function (Blueprint $table): void {
            $table->dropColumn('legal_reference');
        });
        Schema::dropIfExists('statutory_layouts');
        Schema::dropIfExists('pension_rules');
    }

    /**
     * ETHR's own layouts (research F1–F3: no official layout was found). A
     * label may carry {employee_rate}, {employer_rate} and {total_rate}, filled
     * from the rates the run actually applied.
     *
     * @return list<array<string, mixed>>
     */
    private static function layouts(): array
    {
        $note = 'Layout prepared by ETHR; to be confirmed with the agency before filing (owner action O3).';
        $pension = [
            ['key' => 'no', 'label' => 'No'],
            ['key' => 'tin', 'label' => 'Employee TIN'],
            ['key' => 'employee_name', 'label' => 'Employee Name'],
            ['key' => 'employee_code', 'label' => 'Employee Code'],
            ['key' => 'pensionable', 'label' => 'Pensionable Salary (ETB)'],
            ['key' => 'employee_pension', 'label' => 'Employee {employee_rate}% (ETB)'],
            ['key' => 'employer_pension', 'label' => 'Employer {employer_rate}% (ETB)'],
            ['key' => 'total_pension', 'label' => 'Total {total_rate}% (ETB)'],
        ];

        return [
            [
                'schedule' => 'income-tax', 'scheme' => null,
                'agency' => 'Ministry of Revenues',
                'title' => 'Employment Income Tax Schedule',
                'columns' => [
                    ['key' => 'no', 'label' => 'No'],
                    ['key' => 'tin', 'label' => 'Employee TIN'],
                    ['key' => 'employee_name', 'label' => 'Employee Name'],
                    ['key' => 'employee_code', 'label' => 'Employee Code'],
                    ['key' => 'start_date', 'label' => 'Start Date'],
                    ['key' => 'basic', 'label' => 'Basic Salary (ETB)'],
                    ['key' => 'overtime', 'label' => 'Overtime (ETB)'],
                    ['key' => 'allowances', 'label' => 'Allowances (ETB)'],
                    ['key' => 'taxable', 'label' => 'Taxable Income (ETB)'],
                    ['key' => 'income_tax', 'label' => 'Tax Withheld (ETB)'],
                ],
                'note' => $note, 'effective_from' => '2016-07-08', 'effective_to' => null,
            ],
            [
                'schedule' => 'pension', 'scheme' => 'private',
                'agency' => "Private Organization Employees' Social Security Agency (POESSA)",
                'title' => 'Pension Contribution Schedule',
                'columns' => $pension,
                'note' => $note, 'effective_from' => '2022-03-18', 'effective_to' => null,
            ],
            [
                'schedule' => 'pension', 'scheme' => 'public',
                'agency' => "Public Servants' Social Security Agency (PSSSA) — Civil Service Pension Fund",
                'title' => 'Pension Contribution Schedule',
                'columns' => $pension,
                'note' => $note, 'effective_from' => '2022-03-18', 'effective_to' => null,
            ],
            [
                'schedule' => 'pension', 'scheme' => 'public_military',
                'agency' => "Public Servants' Social Security Agency (PSSSA) — Military and Police Service Pension Fund",
                'title' => 'Pension Contribution Schedule',
                'columns' => $pension,
                'note' => $note, 'effective_from' => '2022-03-18', 'effective_to' => null,
            ],
        ];
    }
};
