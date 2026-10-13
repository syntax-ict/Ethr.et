<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * How an organisation's people work, independent of what the organisation does.
 *
 * An industry (IndustryCatalog) says *what* an organisation is; a workforce
 * profile says *when* its people work: office hours, a six-day week, three
 * rotating shifts, round-the-clock cover. Composing the two is what lets
 * "hospital + 24/7 rotation" and "hospital + office hours" exist without a
 * template per combination.
 *
 * Every profile is expressed only in what the shift engine already runs:
 * `shifts` rows (wall-clock times, ISO working days 1 = Mon … 7 = Sun, a break,
 * grace and early-departure tolerances), `shift_rotations` with day-offset
 * steps, the tenant's `working_days` setting, and per-field attendance
 * settings. Nothing here is a schedule type the engine does not understand;
 * split shifts and on-call cover are absent for that reason (see
 * INDUSTRY_TEMPLATES.md, "What templates cannot configure").
 *
 * Hours are kept at or under 48 a week on average, the ceiling in Labour
 * Proclamation 1156/2019 art. 61 — WorkforceProfileCatalogTest asserts it for
 * every rotation, so a profile cannot be added that breaks it unnoticed.
 */
final class WorkforceProfileCatalog
{
    /**
     * Rotation steps name shifts by `name`, the same natural key the provisioner
     * uses, and `null` is a rest day. A step's shift must be one of the
     * profile's own shifts.
     *
     * @var array<string, array{
     *     label: string,
     *     label_am: string,
     *     description: string,
     *     description_am: string,
     *     shifts: list<array<string, mixed>>,
     *     rotations?: list<array{name: string, name_am?: string, description?: string, cycle_days: int, steps: list<array{day_offset: int, shift: string|null}>}>,
     *     working_days: list<int>,
     *     attendance?: array<string, mixed>,
     *     review?: list<string>
     * }>
     */
    private const PROFILES = [
        'standard_office' => [
            'label' => 'Standard office hours',
            'label_am' => 'መደበኛ የቢሮ ሰዓት',
            'description' => 'Monday to Friday, 08:30–17:30 with an hour for lunch.',
            'description_am' => 'ከሰኞ እስከ ዓርብ፣ 08:30–17:30፣ የአንድ ሰዓት የምሳ እረፍት።',
            'shifts' => [
                ['name' => 'Regular', 'name_am' => 'መደበኛ', 'start_time' => '08:30', 'end_time' => '17:30', 'working_days' => '1,2,3,4,5', 'break_minutes' => 60],
            ],
            'working_days' => [1, 2, 3, 4, 5],
        ],
        'six_day_week' => [
            'label' => 'Six-day week (Saturday morning)',
            'label_am' => 'የስድስት ቀን ሳምንት (ቅዳሜ ጠዋት)',
            'description' => 'Monday to Friday 08:00–17:00 and Saturday 08:00–12:00.',
            'description_am' => 'ከሰኞ እስከ ዓርብ 08:00–17:00 እና ቅዳሜ 08:00–12:00።',
            'shifts' => [
                ['name' => 'Weekday', 'name_am' => 'የሥራ ቀን', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => '1,2,3,4,5', 'break_minutes' => 60],
                ['name' => 'Saturday', 'name_am' => 'ቅዳሜ', 'start_time' => '08:00', 'end_time' => '12:00', 'working_days' => '6', 'break_minutes' => 0],
            ],
            'rotations' => [[
                'name' => 'Weekdays + Saturday morning',
                'name_am' => 'የሥራ ቀናት + ቅዳሜ ጠዋት',
                'description' => 'One week. Anchor it on a Monday.',
                'cycle_days' => 7,
                'steps' => [
                    ['day_offset' => 0, 'shift' => 'Weekday'],
                    ['day_offset' => 1, 'shift' => 'Weekday'],
                    ['day_offset' => 2, 'shift' => 'Weekday'],
                    ['day_offset' => 3, 'shift' => 'Weekday'],
                    ['day_offset' => 4, 'shift' => 'Weekday'],
                    ['day_offset' => 5, 'shift' => 'Saturday'],
                    ['day_offset' => 6, 'shift' => null],
                ],
            ]],
            'working_days' => [1, 2, 3, 4, 5, 6],
        ],
        'flexible_hybrid' => [
            'label' => 'Flexible start / hybrid',
            'label_am' => 'ተለዋዋጭ መግቢያ / ድብልቅ',
            'description' => 'An 08:00–17:00 day where arriving by 10:00 still counts as on time.',
            'description_am' => 'የ08:00–17:00 ቀን፤ እስከ 10:00 የገባ በሰዓቱ እንደገባ ይቆጠራል።',
            'shifts' => [
                ['name' => 'Flexible day', 'name_am' => 'ተለዋዋጭ ቀን', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => '1,2,3,4,5', 'break_minutes' => 60, 'grace_minutes' => 120, 'early_departure_minutes' => 60],
            ],
            'working_days' => [1, 2, 3, 4, 5],
            'attendance' => ['grace_period_minutes' => 120],
            'review' => ['flexible_is_grace'],
        ],
        'rotating_three_shift' => [
            'label' => 'Three rotating shifts, six days',
            'label_am' => 'ሦስት ተዘዋዋሪ ፈረቃዎች፣ ስድስት ቀን',
            'description' => 'Day, swing and night shifts of eight hours, Monday to Saturday, rotating weekly.',
            'description_am' => 'የቀን፣ የከሰዓትና የሌሊት የስምንት ሰዓት ፈረቃዎች፣ ከሰኞ እስከ ቅዳሜ፣ በየሳምንቱ የሚዞሩ።',
            'shifts' => [
                ['name' => 'Day', 'name_am' => 'ቀን', 'start_time' => '06:00', 'end_time' => '14:00', 'working_days' => '1,2,3,4,5,6', 'break_minutes' => 30],
                ['name' => 'Swing', 'name_am' => 'ከሰዓት', 'start_time' => '14:00', 'end_time' => '22:00', 'working_days' => '1,2,3,4,5,6', 'break_minutes' => 30],
                ['name' => 'Night', 'name_am' => 'ሌሊት', 'start_time' => '22:00', 'end_time' => '06:00', 'working_days' => '1,2,3,4,5,6', 'break_minutes' => 30],
            ],
            'rotations' => [[
                'name' => 'Weekly day / swing / night',
                'name_am' => 'ሳምንታዊ ቀን / ከሰዓት / ሌሊት',
                'description' => 'Three weeks: six days on each shift, then a rest day. Stagger crews by a week.',
                'cycle_days' => 21,
                'steps' => [
                    ['day_offset' => 0, 'shift' => 'Day'], ['day_offset' => 1, 'shift' => 'Day'], ['day_offset' => 2, 'shift' => 'Day'],
                    ['day_offset' => 3, 'shift' => 'Day'], ['day_offset' => 4, 'shift' => 'Day'], ['day_offset' => 5, 'shift' => 'Day'],
                    ['day_offset' => 6, 'shift' => null],
                    ['day_offset' => 7, 'shift' => 'Swing'], ['day_offset' => 8, 'shift' => 'Swing'], ['day_offset' => 9, 'shift' => 'Swing'],
                    ['day_offset' => 10, 'shift' => 'Swing'], ['day_offset' => 11, 'shift' => 'Swing'], ['day_offset' => 12, 'shift' => 'Swing'],
                    ['day_offset' => 13, 'shift' => null],
                    ['day_offset' => 14, 'shift' => 'Night'], ['day_offset' => 15, 'shift' => 'Night'], ['day_offset' => 16, 'shift' => 'Night'],
                    ['day_offset' => 17, 'shift' => 'Night'], ['day_offset' => 18, 'shift' => 'Night'], ['day_offset' => 19, 'shift' => 'Night'],
                    ['day_offset' => 20, 'shift' => null],
                ],
            ]],
            'working_days' => [1, 2, 3, 4, 5, 6],
        ],
        'continuous_24_7' => [
            'label' => 'Round the clock (24/7)',
            'label_am' => 'ሌት ተቀን (24/7)',
            'description' => 'Morning, afternoon and night every day, covered by four crews on a 2-2-2-2 rotation.',
            'description_am' => 'ጠዋት፣ ከሰዓትና ሌሊት በየቀኑ፣ በ2-2-2-2 ሽክርክሪት በአራት ቡድኖች የሚሸፈን።',
            'shifts' => [
                ['name' => 'Morning', 'name_am' => 'ጠዋት', 'start_time' => '06:00', 'end_time' => '14:00', 'working_days' => '1,2,3,4,5,6,7', 'break_minutes' => 30],
                ['name' => 'Afternoon', 'name_am' => 'ከሰዓት', 'start_time' => '14:00', 'end_time' => '22:00', 'working_days' => '1,2,3,4,5,6,7', 'break_minutes' => 30],
                ['name' => 'Night', 'name_am' => 'ሌሊት', 'start_time' => '22:00', 'end_time' => '06:00', 'working_days' => '1,2,3,4,5,6,7', 'break_minutes' => 30],
            ],
            'rotations' => [[
                'name' => 'Continuous 2-2-2-2',
                'name_am' => 'ቀጣይ 2-2-2-2',
                'description' => 'Eight days: two mornings, two afternoons, two nights, two off. Four crews anchored two days apart cover every shift every day.',
                'cycle_days' => 8,
                'steps' => [
                    ['day_offset' => 0, 'shift' => 'Morning'], ['day_offset' => 1, 'shift' => 'Morning'],
                    ['day_offset' => 2, 'shift' => 'Afternoon'], ['day_offset' => 3, 'shift' => 'Afternoon'],
                    ['day_offset' => 4, 'shift' => 'Night'], ['day_offset' => 5, 'shift' => 'Night'],
                    ['day_offset' => 6, 'shift' => null], ['day_offset' => 7, 'shift' => null],
                ],
            ]],
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
            'attendance' => ['auto_checkout_enabled' => true],
            'review' => ['rotation_crews'],
        ],
        'extended_hours_two_shift' => [
            'label' => 'Extended hours, opening and closing shifts',
            'label_am' => 'የተራዘመ ሰዓት፣ የመክፈቻና የመዝጊያ ፈረቃ',
            'description' => 'Open 07:00–22:00 every day with an opening and a closing shift that overlap at midday.',
            'description_am' => 'በየቀኑ 07:00–22:00 ክፍት፣ እኩለ ቀን ላይ የሚደራረቡ የመክፈቻና የመዝጊያ ፈረቃዎች።',
            'shifts' => [
                ['name' => 'Opening', 'name_am' => 'መክፈቻ', 'start_time' => '07:00', 'end_time' => '15:00', 'working_days' => '1,2,3,4,5,6,7', 'break_minutes' => 30],
                ['name' => 'Closing', 'name_am' => 'መዝጊያ', 'start_time' => '14:00', 'end_time' => '22:00', 'working_days' => '1,2,3,4,5,6,7', 'break_minutes' => 30],
            ],
            'rotations' => [[
                'name' => 'Opening / closing, alternate weeks',
                'name_am' => 'መክፈቻ / መዝጊያ፣ በየሳምንቱ',
                'description' => 'Two weeks: six opening shifts and a rest day, then six closing shifts and a rest day.',
                'cycle_days' => 14,
                'steps' => [
                    ['day_offset' => 0, 'shift' => 'Opening'], ['day_offset' => 1, 'shift' => 'Opening'], ['day_offset' => 2, 'shift' => 'Opening'],
                    ['day_offset' => 3, 'shift' => 'Opening'], ['day_offset' => 4, 'shift' => 'Opening'], ['day_offset' => 5, 'shift' => 'Opening'],
                    ['day_offset' => 6, 'shift' => null],
                    ['day_offset' => 7, 'shift' => 'Closing'], ['day_offset' => 8, 'shift' => 'Closing'], ['day_offset' => 9, 'shift' => 'Closing'],
                    ['day_offset' => 10, 'shift' => 'Closing'], ['day_offset' => 11, 'shift' => 'Closing'], ['day_offset' => 12, 'shift' => 'Closing'],
                    ['day_offset' => 13, 'shift' => null],
                ],
            ]],
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'part_time' => [
            'label' => 'Part-time half days',
            'label_am' => 'የግማሽ ቀን የትርፍ ጊዜ',
            'description' => 'Morning and afternoon half days, Monday to Friday.',
            'description_am' => 'የጠዋትና የከሰዓት ግማሽ ቀናት፣ ከሰኞ እስከ ዓርብ።',
            'shifts' => [
                ['name' => 'Half day (morning)', 'name_am' => 'ግማሽ ቀን (ጠዋት)', 'start_time' => '08:30', 'end_time' => '12:30', 'working_days' => '1,2,3,4,5', 'break_minutes' => 0],
                ['name' => 'Half day (afternoon)', 'name_am' => 'ግማሽ ቀን (ከሰዓት)', 'start_time' => '13:30', 'end_time' => '17:30', 'working_days' => '1,2,3,4,5', 'break_minutes' => 0],
            ],
            'working_days' => [1, 2, 3, 4, 5],
        ],
        'field_distributed' => [
            'label' => 'Field and distributed teams',
            'label_am' => 'የመስክና የተበተኑ ቡድኖች',
            'description' => 'A 08:00–17:00 day checked in by phone with a photo, closed automatically if someone forgets.',
            'description_am' => 'የ08:00–17:00 ቀን፤ በስልክ ከፎቶ ጋር መግቢያ፣ ቢረሳ በራሱ የሚዘጋ መውጫ።',
            'shifts' => [
                ['name' => 'Field day', 'name_am' => 'የመስክ ቀን', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => '1,2,3,4,5', 'break_minutes' => 60, 'grace_minutes' => 30],
            ],
            'working_days' => [1, 2, 3, 4, 5],
            'attendance' => [
                'mobile_photo_required' => true,
                'auto_checkout_enabled' => true,
            ],
        ],
        'seasonal_agricultural' => [
            'label' => 'Seasonal / agricultural',
            'label_am' => 'ወቅታዊ / ግብርና',
            'description' => 'An early field shift, 06:30–14:30, Monday to Saturday.',
            'description_am' => 'ማለዳ የሚጀምር የመስክ ፈረቃ፣ 06:30–14:30፣ ከሰኞ እስከ ቅዳሜ።',
            'shifts' => [
                ['name' => 'Field shift', 'name_am' => 'የመስክ ፈረቃ', 'start_time' => '06:30', 'end_time' => '14:30', 'working_days' => '1,2,3,4,5,6', 'break_minutes' => 30],
            ],
            'working_days' => [1, 2, 3, 4, 5, 6],
            'attendance' => ['auto_checkout_enabled' => true],
            'review' => ['seasonal_contracts'],
        ],
        'weekend' => [
            'label' => 'Weekend staff',
            'label_am' => 'የሳምንት መጨረሻ ሠራተኞች',
            'description' => 'Saturday and Sunday, 08:00–17:00.',
            'description_am' => 'ቅዳሜና እሑድ፣ 08:00–17:00።',
            'shifts' => [
                ['name' => 'Weekend', 'name_am' => 'የሳምንት መጨረሻ', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => '6,7', 'break_minutes' => 60],
            ],
            'working_days' => [6, 7],
        ],
    ];

    public const DEFAULT = 'standard_office';

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
     * @return array{key: string, label: string, label_am: string, description: string, description_am: string, shifts: list<array<string, mixed>>, rotations: list<array<string, mixed>>, working_days: list<int>, attendance: array<string, mixed>, review: list<string>}|null
     */
    public function find(string $key): ?array
    {
        $entry = self::PROFILES[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $entry['label'],
            'label_am' => $entry['label_am'],
            'description' => $entry['description'],
            'description_am' => $entry['description_am'],
            'shifts' => $entry['shifts'],
            'rotations' => $entry['rotations'] ?? [],
            'working_days' => $entry['working_days'],
            'attendance' => $entry['attendance'] ?? [],
            'review' => $entry['review'] ?? [],
        ];
    }

    /**
     * For a picker: what each profile is, not its full definition.
     *
     * @return list<array{key: string, label: string, label_am: string, description: string, description_am: string}>
     */
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
