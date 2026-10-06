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
 */
final class IndustryProfileResolver
{
    /** Below this headcount, default to mobile check-in rather than fixed devices. */
    private const SMALL_ORG_THRESHOLD = 25;

    /** @var array<int, string> */
    private const LIST_SECTIONS = ['branches', 'departments', 'positions', 'grades', 'shifts', 'leave_types'];

    public function __construct(private readonly IndustryCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $signals  e.g. ['employee_count' => 120, 'region' => 'Oromia']
     */
    public function resolve(string $industryKey, array $signals = []): ConfigurationPlan
    {
        $industry = $this->catalog->find($industryKey) ?? $this->catalog->find('custom');
        /** @var array{key: string, label: string, label_am: string, base: string, icon: string, group: string, overrides: array<string, mixed>} $industry */
        $base = $this->baseTemplateData($industry['base']);
        $overrides = $industry['overrides'];

        $sections = [];

        foreach (self::LIST_SECTIONS as $key) {
            if (array_key_exists($key, $overrides) && is_array($overrides[$key])) {
                $sections[$key] = ['source' => ConfigurationSource::EXPLICIT, 'items' => array_values($overrides[$key])];
            } elseif (array_key_exists($key, $base) && is_array($base[$key])) {
                $sections[$key] = ['source' => ConfigurationSource::INDUSTRY_DEFAULT, 'items' => array_values($base[$key])];
            }
        }

        $this->applyBranchHeuristic($sections, $signals);
        $this->applySettings($sections, $base, $overrides, $signals);

        return new ConfigurationPlan($industry, $signals, $sections);
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
