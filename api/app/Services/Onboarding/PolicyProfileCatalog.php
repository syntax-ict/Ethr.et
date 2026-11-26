<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * Which body of employment law an organisation's policies start from.
 *
 * Ethiopia has two, and they are not interchangeable: Labour Proclamation
 * 1156/2019 governs private employers and explicitly excludes civil servants,
 * who are governed by the Federal Civil Servants Proclamation 1353/2024
 * (which repealed 1064/2017; docs/EMPLOYMENT-LAW-REGISTER.md) and
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
     *     label_om: string,
     *     description: string,
     *     description_am: string,
     *     description_om: string,
     *     leave_types: list<string>,
     *     fiscal_year_start_month: int,
     *     allowances: list<array{name: string, name_am: string, name_om: string}>,
     *     review: list<string>
     * }>
     */
    private const PROFILES = [
        'ethiopia_private' => [
            'label' => 'Ethiopian private sector',
            'label_am' => 'የኢትዮጵያ የግል ዘርፍ',
            'label_om' => 'Kutaa Dhuunfaa Itoophiyaa',
            'description' => 'Starts from Labour Proclamation 1156/2019. Fiscal year from Meskerem.',
            'description_am' => 'ከሠራተኛ አዋጅ ቁጥር 1156/2011 ይጀምራል። የበጀት ዓመት ከመስከረም።',
            'description_om' => 'Labsii Hojjetaa Lakk. 1156/2019 irraa jalqaba. Bara baajetaa Fulbaana irraa eegala.',
            'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'marriage', 'bereavement', 'unpaid'],
            'fiscal_year_start_month' => 1,
            'allowances' => [
                ['name' => 'Transport allowance', 'name_am' => 'የትራንስፖርት አበል', 'name_om' => 'Abbaltii Geejjibaa'],
                ['name' => 'Housing allowance', 'name_am' => 'የቤት አበል', 'name_om' => 'Abbaltii Mana Jireenyaa'],
                ['name' => 'Position allowance', 'name_am' => 'የኃላፊነት አበል', 'name_om' => 'Abbaltii Itti Gaafatamummaa'],
            ],
            'review' => ['leave_private', 'allowances_inactive', 'grades_illustrative', 'statutory_engine'],
        ],
        'ethiopia_public' => [
            'label' => 'Ethiopian public sector (civil service)',
            'label_am' => 'የኢትዮጵያ የመንግሥት ዘርፍ (ሲቪል ሰርቪስ)',
            'label_om' => 'Kutaa Mootummaa Itoophiyaa (Tajaajila Siivilii)',
            'description' => 'For civil servants, who are outside the Labour Proclamation. Fiscal year from Hamle.',
            'description_am' => 'ከሠራተኛ አዋጁ ውጪ ለሆኑ የመንግሥት ሠራተኞች። የበጀት ዓመት ከሐምሌ።',
            'description_om' => 'Hojjettoota mootummaa Labsii Hojjetaa alatti argaman kanaaf. Bara baajetaa Adoolessa irraa eegala.',
            'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'marriage', 'bereavement', 'study', 'unpaid'],
            'fiscal_year_start_month' => 11,
            'allowances' => [
                ['name' => 'Transport allowance', 'name_am' => 'የትራንስፖርት አበል', 'name_om' => 'Abbaltii Geejjibaa'],
                ['name' => 'Housing allowance', 'name_am' => 'የቤት አበል', 'name_om' => 'Abbaltii Mana Jireenyaa'],
                ['name' => 'Position allowance', 'name_am' => 'የኃላፊነት አበል', 'name_om' => 'Abbaltii Itti Gaafatamummaa'],
            ],
            'review' => ['leave_public', 'payroll_public', 'allowances_inactive', 'grades_illustrative', 'statutory_engine'],
        ],
        'custom' => [
            'label' => 'Set my own policies',
            'label_am' => 'የራሴን ፖሊሲ አዘጋጃለሁ',
            'label_om' => 'Imaammata koo ofiin qopheessa',
            'description' => 'Only annual, sick and unpaid leave as a starting point. Fiscal year from Meskerem.',
            'description_am' => 'እንደ መነሻ ዓመታዊ፣ የሕመምና ያለክፍያ ፈቃድ ብቻ። የበጀት ዓመት ከመስከረም።',
            'description_om' => 'Jalqabaaf boqonnaa waggaa, dhukkubaa fi mindaa malee qofa. Bara baajetaa Fulbaana irraa eegala.',
            'leave_types' => ['annual', 'sick', 'unpaid'],
            'fiscal_year_start_month' => 1,
            'allowances' => [],
            'review' => ['leave_custom', 'grades_illustrative', 'statutory_engine'],
        ],
    ];

    /**
     * The organisation types whose staff are outside the Labour Proclamation:
     * federal and regional civil servants, and defence and police, who have
     * laws of their own. A type that inherits from one (regional_government)
     * is too. Every other kind starts from the Labour Proclamation.
     *
     * @var list<string>
     */
    public const CIVIL_SERVICE_TYPES = ['government', 'defence_police'];

    /**
     * The profile an organisation's kind in law starts from, given the kind
     * and its parent (ProfileContext::lineage()); null when no kind is known,
     * so the industry's suggestion stands.
     *
     * @param  list<string>  $lineage
     */
    public function forLineage(array $lineage): ?string
    {
        if ($lineage === []) {
            return null;
        }

        return array_intersect($lineage, self::CIVIL_SERVICE_TYPES) === [] ? 'ethiopia_private' : 'ethiopia_public';
    }

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
     * @return array{key: string, label: string, label_am: string, label_om: string, description: string, description_am: string, description_om: string, leave_types: list<string>, fiscal_year_start_month: int, allowances: list<array{name: string, name_am: string, name_om: string}>, review: list<string>}|null
     */
    public function find(string $key): ?array
    {
        $entry = self::PROFILES[$key] ?? null;

        return $entry === null ? null : ['key' => $key, ...$entry];
    }

    /** @return list<array{key: string, label: string, label_am: string, label_om: string, description: string, description_am: string, description_om: string}> */
    public function all(): array
    {
        $out = [];

        foreach (self::PROFILES as $key => $entry) {
            $out[] = [
                'key' => $key,
                'label' => $entry['label'],
                'label_am' => $entry['label_am'],
                'label_om' => $entry['label_om'],
                'description' => $entry['description'],
                'description_am' => $entry['description_am'],
                'description_om' => $entry['description_om'],
            ];
        }

        return $out;
    }
}
