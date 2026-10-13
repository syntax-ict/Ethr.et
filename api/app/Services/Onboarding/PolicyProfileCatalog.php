<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * Which body of employment law an organisation's policies start from.
 *
 * Ethiopia has two, and they are not interchangeable: Labour Proclamation
 * 1156/2019 governs private employers and explicitly excludes civil servants,
 * who are governed by the Federal Civil Servants Proclamation 1064/2017 and
 * the regions' own civil service laws. A government template that inherited
 * private-sector leave rules would be quietly wrong, so the choice is explicit
 * and every legally sensitive default it produces is flagged for review rather
 * than presented as compliant.
 *
 * What a profile decides: the statutory leave types (values from
 * LeaveTypeCatalog, flagged), the fiscal year start, and allowance
 * *definitions* — created inactive with a zero amount, because an allowance's
 * amount and tax treatment are the organisation's decision and the payroll
 * engine applies an active allowance to every employee. Income tax and pension
 * are not here: the payroll engine applies them from TaxCalculator and
 * PensionCalculator, and a template must not restate them.
 */
final class PolicyProfileCatalog
{
    /**
     * `fiscal_year_start_month` is an Ethiopian month, 1 = Meskerem … 11 = Hamle
     * … 13 = Pagume — the same scale as `tenants.settings`.
     *
     * @var array<string, array{
     *     label: string,
     *     label_am: string,
     *     description: string,
     *     description_am: string,
     *     leave_types: list<string>,
     *     fiscal_year_start_month: int,
     *     allowances: list<array{name: string, name_am: string}>,
     *     review: list<string>
     * }>
     */
    private const PROFILES = [
        'ethiopia_private' => [
            'label' => 'Ethiopia — private sector',
            'label_am' => 'ኢትዮጵያ — የግል ዘርፍ',
            'description' => 'Starts from Labour Proclamation 1156/2019. Fiscal year from Meskerem.',
            'description_am' => 'ከሠራተኛ አዋጅ ቁጥር 1156/2011 ይጀምራል። የበጀት ዓመት ከመስከረም።',
            'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'marriage', 'bereavement', 'unpaid'],
            'fiscal_year_start_month' => 1,
            'allowances' => [
                ['name' => 'Transport allowance', 'name_am' => 'የትራንስፖርት አበል'],
                ['name' => 'Housing allowance', 'name_am' => 'የቤት አበል'],
                ['name' => 'Position allowance', 'name_am' => 'የኃላፊነት አበል'],
            ],
            'review' => ['leave_private', 'allowances_inactive', 'grades_illustrative', 'statutory_engine'],
        ],
        'ethiopia_public' => [
            'label' => 'Ethiopia — public sector (civil service)',
            'label_am' => 'ኢትዮጵያ — የመንግሥት ዘርፍ (ሲቪል ሰርቪስ)',
            'description' => 'For civil servants, who are outside the Labour Proclamation. Fiscal year from Hamle.',
            'description_am' => 'ከሠራተኛ አዋጁ ውጪ ለሆኑ የመንግሥት ሠራተኞች። የበጀት ዓመት ከሐምሌ።',
            'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'marriage', 'bereavement', 'study', 'unpaid'],
            'fiscal_year_start_month' => 11,
            'allowances' => [
                ['name' => 'Transport allowance', 'name_am' => 'የትራንስፖርት አበል'],
                ['name' => 'Housing allowance', 'name_am' => 'የቤት አበል'],
                ['name' => 'Position allowance', 'name_am' => 'የኃላፊነት አበል'],
            ],
            'review' => ['leave_public', 'payroll_public', 'allowances_inactive', 'grades_illustrative', 'statutory_engine'],
        ],
        'custom' => [
            'label' => 'Set my own policies',
            'label_am' => 'የራሴን ፖሊሲ አዘጋጃለሁ',
            'description' => 'Only annual, sick and unpaid leave as a starting point. Fiscal year from Meskerem.',
            'description_am' => 'እንደ መነሻ ዓመታዊ፣ የሕመምና ያለክፍያ ፈቃድ ብቻ። የበጀት ዓመት ከመስከረም።',
            'leave_types' => ['annual', 'sick', 'unpaid'],
            'fiscal_year_start_month' => 1,
            'allowances' => [],
            'review' => ['leave_custom', 'grades_illustrative', 'statutory_engine'],
        ],
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::PROFILES);
    }

    public function has(string $key): bool
    {
        return isset(self::PROFILES[$key]);
    }

    /**
     * @return array{key: string, label: string, label_am: string, description: string, description_am: string, leave_types: list<string>, fiscal_year_start_month: int, allowances: list<array{name: string, name_am: string}>, review: list<string>}|null
     */
    public function find(string $key): ?array
    {
        $entry = self::PROFILES[$key] ?? null;

        return $entry === null ? null : ['key' => $key, ...$entry];
    }

    /** @return list<array{key: string, label: string, label_am: string, description: string, description_am: string}> */
    public function all(): array
    {
        $out = [];

        foreach (self::PROFILES as $key => $entry) {
            $out[] = [
                'key' => $key,
                'label' => $entry['label'],
                'label_am' => $entry['label_am'],
                'description' => $entry['description'],
                'description_am' => $entry['description_am'],
            ];
        }

        return $out;
    }
}
