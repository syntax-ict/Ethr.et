<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\ConfigurationSource;
use App\Models\OrganizationTemplate;

/**
 * Turns an industry selection plus tenant signals into a scored, explainable
 * ConfigurationPlan.
 *
 * This is the deterministic "smart configuration" engine — no model call. Each
 * section's provenance follows a fixed precedence (ONBOARDING_V2.md D2):
 *
 *   1. industry override patch      → EXPLICIT (1.0)
 *   2. base organization template   → INDUSTRY_DEFAULT (0.8)
 *   3. derived from tenant signals  → HEURISTIC (0.6)
 *   4. nothing applied              → GLOBAL_FALLBACK (0.4)
 *
 * `settings` is the one section that deep-merges the base and the override
 * rather than replacing; the other sections replace wholesale so provenance
 * stays a single value per section.
 *
 * Two further choices compose with the industry (INDUSTRY_TEMPLATES.md,
 * "Composing a plan"): a WorkforceProfileCatalog entry supplies the shifts,
 * rotations, working week and attendance settings, and a PolicyProfileCatalog
 * entry supplies the statutory leave types, fiscal year and allowance
 * definitions. Each defaults from the industry; a signal naming one makes its
 * sections EXPLICIT. Legally sensitive defaults are not silently applied — they
 * come back as `review` notes the admin confirms in the review step.
 */
final class IndustryProfileResolver
{
    /** Below this headcount, default to mobile check-in rather than fixed devices. */
    private const SMALL_ORG_THRESHOLD = 25;

    /**
     * Sections taken from the industry template and its override. Shifts and
     * leave types are not here: the workforce and policy profiles own them.
     *
     * @var array<int, string>
     */
    private const LIST_SECTIONS = ['branches', 'departments', 'positions', 'grades'];

    public function __construct(
        private readonly IndustryCatalog $catalog,
        private readonly WorkforceProfileCatalog $workforceProfiles,
        private readonly PolicyProfileCatalog $policyProfiles,
        private readonly LeaveTypeCatalog $leaveTypes,
    ) {}

    /**
     * @param  array<string, mixed>  $signals  e.g. ['employee_count' => 120, 'region' => 'Oromia',
     *                                         'workforce_profile' => 'continuous_24_7', 'policy_profile' => 'ethiopia_public']
     */
    public function resolve(string $industryKey, array $signals = []): ConfigurationPlan
    {
        $industry = $this->catalog->find($industryKey) ?? $this->catalog->find('custom');
        /** @var array{key: string, label: string, label_am: string, base: string, icon: string, group: string, profile: string, policy: string, overrides: array<string, mixed>} $industry */
        $base = $this->baseTemplateData($industry['base']);
        $overrides = $industry['overrides'];

        $profileChosen = is_string($signals['workforce_profile'] ?? null) && $this->workforceProfiles->has($signals['workforce_profile']);
        $policyChosen = is_string($signals['policy_profile'] ?? null) && $this->policyProfiles->has($signals['policy_profile']);

        $profile = $this->workforceProfiles->find($profileChosen ? $signals['workforce_profile'] : $industry['profile'])
            ?? $this->workforceProfiles->find(WorkforceProfileCatalog::DEFAULT);
        $policy = $this->policyProfiles->find($policyChosen ? $signals['policy_profile'] : $industry['policy'])
            ?? $this->policyProfiles->find('ethiopia_private');
        /** @var array{key: string, label: string, label_am: string, description: string, shifts: list<array<string, mixed>>, rotations: list<array<string, mixed>>, working_days: list<int>, attendance: array<string, mixed>, review: list<string>} $profile */
        /** @var array{key: string, label: string, label_am: string, description: string, leave_types: list<string>, fiscal_year_start_month: int, allowances: list<array{name: string, name_am: string}>, review: list<string>} $policy */
        $sections = [];

        foreach (self::LIST_SECTIONS as $key) {
            if (array_key_exists($key, $overrides) && is_array($overrides[$key])) {
                $sections[$key] = ['source' => ConfigurationSource::EXPLICIT, 'items' => array_values($overrides[$key])];
            } elseif (array_key_exists($key, $base) && is_array($base[$key])) {
                $sections[$key] = ['source' => ConfigurationSource::INDUSTRY_DEFAULT, 'items' => array_values($base[$key])];
            }
        }

        $this->applyBranchHeuristic($sections, $signals);
        $this->applyWorkforceProfile($sections, $profile, $profileChosen);
        $this->applyPolicyProfile($sections, $policy, $policyChosen, $base, $overrides);
        $this->applySettings($sections, $base, $overrides, $signals);
        $this->applyProfileSettings($sections, $profile, $policy);

        $review = array_values(array_unique([...$policy['review'], ...$profile['review']]));

        return new ConfigurationPlan(
            $industry,
            $signals,
            $sections,
            [
                'workforce' => ['key' => $profile['key'], 'label' => $profile['label'], 'label_am' => $profile['label_am']],
                'policy' => ['key' => $policy['key'], 'label' => $policy['label'], 'label_am' => $policy['label_am']],
            ],
            array_map(static fn (string $code): array => ['code' => $code, 'message' => __('onboarding.review.'.$code)], $review),
        );
    }

    /**
     * Shifts, rotations and attendance settings come from the workforce profile.
     * A profile the admin picked is EXPLICIT; the industry's usual one is an
     * industry default.
     *
     * @param  array<string, array{source: ConfigurationSource, items: mixed}>  $sections
     * @param  array{shifts: list<array<string, mixed>>, rotations: list<array<string, mixed>>, attendance: array<string, mixed>}  $profile
     */
    private function applyWorkforceProfile(array &$sections, array $profile, bool $chosen): void
    {
        $source = $chosen ? ConfigurationSource::EXPLICIT : ConfigurationSource::INDUSTRY_DEFAULT;

        $sections['shifts'] = ['source' => $source, 'items' => $profile['shifts']];

        if ($profile['rotations'] !== []) {
            $sections['rotations'] = ['source' => $source, 'items' => $profile['rotations']];
        }

        if ($profile['attendance'] !== []) {
            $sections['attendance'] = ['source' => $source, 'items' => $profile['attendance']];
        }
    }

    /**
     * Statutory leave types come from the policy profile; a sector's own types
     * (study, sabbatical, injury, R&R) from the template or override are kept
     * after them. Allowance definitions likewise: the policy's common ones, then
     * the industry's.
     *
     * @param  array<string, array{source: ConfigurationSource, items: mixed}>  $sections
     * @param  array{leave_types: list<string>, allowances: list<array{name: string, name_am: string}>}  $policy
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     */
    private function applyPolicyProfile(array &$sections, array $policy, bool $chosen, array $base, array $overrides): void
    {
        $sectorLeave = is_array($overrides['leave_types'] ?? null)
            ? $overrides['leave_types']
            : (is_array($base['leave_types'] ?? null) ? $base['leave_types'] : []);

        // The statutory set is the policy's. From the template only the types
        // that belong to a sector rather than to a law are carried over, so a
        // template cannot reintroduce a type the chosen policy leaves out.
        $sectorOnly = ['study', 'sabbatical', 'injury', 'r_and_r'];
        $leave = $policy['leave_types'];
        foreach ($sectorLeave as $code) {
            if (is_string($code) && in_array($code, $sectorOnly, true) && ! in_array($code, $leave, true)) {
                $leave[] = $code;
            }
        }

        // Each code with the name it will be created under, so the review step
        // shows "Bereavement Leave" rather than `bereavement`. The provisioner
        // reads the code and fills the rest from LeaveTypeCatalog as before.
        $items = [];
        foreach ($leave as $code) {
            $definition = $this->leaveTypes->resolve($code);
            $items[] = $definition === null
                ? $code
                : ['code' => $definition['code'], 'name' => $definition['name'] ?? $code, 'name_am' => $definition['name_am'] ?? null];
        }

        $sections['leave_types'] = [
            'source' => $chosen ? ConfigurationSource::EXPLICIT : ConfigurationSource::INDUSTRY_DEFAULT,
            'items' => $items,
        ];

        $allowances = [];
        foreach ([...$policy['allowances'], ...(is_array($overrides['allowances'] ?? null) ? $overrides['allowances'] : [])] as $allowance) {
            if (is_array($allowance) && is_string($allowance['name'] ?? null)) {
                $allowances[$allowance['name']] = $allowance;
            }
        }

        if ($allowances !== []) {
            $sections['allowances'] = [
                'source' => $chosen ? ConfigurationSource::EXPLICIT : ConfigurationSource::INDUSTRY_DEFAULT,
                'items' => array_values($allowances),
            ];
        }
    }

    /**
     * The settings the runtime actually reads. Templates have always carried a
     * `payroll` namespace (`fiscal_year_start: 'hamle'`, pension rates, …) that
     * nothing consumes; the payroll engine reads `fiscal_year_start_month` and
     * `pagumen_proration_strategy`, and the leave and payroll day counts read
     * `working_days`. Those are set here, from the profiles, and the legacy
     * `payroll.fiscal_year_start` is aligned so the two cannot disagree.
     *
     * @param  array<string, array{source: ConfigurationSource, items: mixed}>  $sections
     * @param  array{working_days: list<int>}  $profile
     * @param  array{fiscal_year_start_month: int}  $policy
     */
    private function applyProfileSettings(array &$sections, array $profile, array $policy): void
    {
        $settings = isset($sections['settings']) && is_array($sections['settings']['items']) ? $sections['settings']['items'] : [];

        $settings['fiscal_year_start_month'] = $policy['fiscal_year_start_month'];
        $settings['pagumen_proration_strategy'] = 'full_month';
        $settings['working_days'] = $profile['working_days'];

        if (isset($settings['payroll']) && is_array($settings['payroll'])) {
            $settings['payroll']['fiscal_year_start'] = $policy['fiscal_year_start_month'] === 11 ? 'hamle' : 'meskerem';
        }

        $sections['settings'] = [
            'source' => $sections['settings']['source'] ?? ConfigurationSource::INDUSTRY_DEFAULT,
            'items' => $settings,
        ];
    }

    /**
     * A tenant with no branch in the template still needs one to hang employees
     * and attendance off. Synthesize a headquarters seeded with the tenant's
     * region — that is a signal-derived guess, hence HEURISTIC.
     *
     * @param  array<string, array{source: ConfigurationSource, items: mixed}>  $sections
     * @param  array<string, mixed>  $signals
     */
    private function applyBranchHeuristic(array &$sections, array $signals): void
    {
        if (isset($sections['branches'])) {
            return;
        }

        $region = is_string($signals['region'] ?? null) && trim($signals['region']) !== ''
            ? trim($signals['region'])
            : 'Addis Ababa';

        $sections['branches'] = [
            'source' => ConfigurationSource::HEURISTIC,
            'items' => [[
                'name' => 'Headquarters',
                'name_am' => 'ዋና መስሪያ ቤት',
                'city' => $region,
                'code' => 'HQ',
            ]],
        ];
    }

    /**
     * Merge base and override settings, then fill an attendance method from the
     * headcount signal when neither supplied one. Provenance is the strongest
     * contributor: EXPLICIT if the override touched settings, else INDUSTRY_DEFAULT
     * if the base did, else HEURISTIC once the size guess fills it in.
     *
     * @param  array<string, array{source: ConfigurationSource, items: mixed}>  $sections
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $signals
     */
    private function applySettings(array &$sections, array $base, array $overrides, array $signals): void
    {
        $baseSettings = is_array($base['settings'] ?? null) ? $base['settings'] : [];
        $overrideSettings = is_array($overrides['settings'] ?? null) ? $overrides['settings'] : [];

        $settings = $this->deepMerge($baseSettings, $overrideSettings);

        $source = $overrideSettings !== []
            ? ConfigurationSource::EXPLICIT
            : ($baseSettings !== [] ? ConfigurationSource::INDUSTRY_DEFAULT : null);

        $method = $settings['attendance']['default_method'] ?? null;

        if (! is_string($method) || $method === '') {
            $count = $signals['employee_count'] ?? null;
            $settings['attendance']['default_method'] = is_int($count) && $count < self::SMALL_ORG_THRESHOLD
                ? 'mobile'
                : 'biometric';

            // Filling a gap from a signal is at best a heuristic — never let this
            // step raise the section's confidence above what the templates earned.
            $source = $source === null ? ConfigurationSource::HEURISTIC : $source;
        }

        if ($settings === []) {
            return;
        }

        $sections['settings'] = [
            'source' => $source ?? ConfigurationSource::GLOBAL_FALLBACK,
            'items' => $settings,
        ];
    }

    /** @return array<string, mixed> */
    private function baseTemplateData(string $slug): array
    {
        $template = OrganizationTemplate::where('slug', $slug)->first();

        if ($template === null) {
            return [];
        }

        // The `template_data` cast yields an array at runtime; read it as the
        // loosely-typed attribute so the array guard stays honest under analysis.
        $data = $template->getAttribute('template_data');

        return is_array($data) ? $data : [];
    }

    /**
     * Recursive array merge where the override wins on scalar collisions and
     * associative arrays merge key-by-key. Used only for the settings map.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
