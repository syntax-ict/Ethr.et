<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Employee;
use App\Models\InvitationSend;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Services\Auth\ActivationCodeService;
use App\Services\Invitations\EmployeeLogins;
use App\Support\EmployeeImportRow;
use Illuminate\Support\Collection;

/**
 * What a list's `sign_in` column (EmployeeImportRow::SIGN_IN) says about who is
 * invited, read from the staged rows and never written to a person.
 *
 * Before the list is added it says what adding it will do (plan()); after, it
 * says what the list asked for each person who still cannot sign in (asked()).
 * A row outranks the tick on the screen: that is decided by the screen, which
 * is told the row's answer.
 */
final class SignInChoices
{
    public function __construct(
        private readonly EmployeeLogins $logins,
        private readonly ActivationCodeService $activation,
    ) {}

    /**
     * Who among the people the list will create an invitation can reach, and
     * who can only be activated in person, each split by what the list says
     * about them. Null for a list that completes people already here, which
     * invites nobody.
     *
     * @param  iterable<MigrationStagingRow>  $rows
     * @return array{people: int, invitable: array{yes: int, no: int, blank: int}, in_person: array{yes: int, no: int, blank: int}, in_person_possible: bool}|null
     */
    public function plan(MigrationBatch $batch, iterable $rows): ?array
    {
        if ($batch->isCompleting()) {
            return null;
        }

        $zero = ['yes' => 0, 'no' => 0, 'blank' => 0];
        $plan = ['people' => 0, 'invitable' => $zero, 'in_person' => $zero];

        // Once, not for each person: whether texts are on is a query.
        $texts = $this->logins->textsEnabled();

        foreach ($rows as $row) {
            if ($row->action !== MigrationStagingRow::ACTION_CREATE) {
                continue;
            }

            $raw = $row->getAttribute('raw');
            $raw = is_array($raw) ? $raw : [];
            $says = $this->says($raw);
            $channel = $this->logins->channelFor($raw['email'] ?? null, $raw['phone'] ?? null, $texts);
            $group = $channel === InvitationSend::IN_PERSON ? 'in_person' : 'invitable';

            $plan['people']++;
            $plan[$group][$says ?? 'blank']++;
        }

        // The organisation-level part of the rule: a person who is only
        // activated in person must be able to sign in afterwards.
        return [...$plan, 'in_person_possible' => $this->activation->canSignInAfterwards(null)];
    }

    /**
     * What the list asked for each employee its rows resolved to, keyed by
     * employee id: true for yes, false for no. Someone the list did not say
     * anything about is not in it.
     *
     * Two rows of one list may resolve to the same person (a merge): `no`
     * wins, so a person a list says not to invite is not invited because
     * another line of it said yes.
     *
     * @return array<int, bool>
     */
    public function asked(MigrationBatch $batch): array
    {
        $asked = [];

        $rows = $batch->rows()
            ->whereNotNull('processed_at')
            ->whereNotNull('resolved_employee_id')
            ->get(['id', 'resolved_employee_id', 'raw']);

        foreach ($rows as $row) {
            $raw = $row->getAttribute('raw');
            $says = $this->says(is_array($raw) ? $raw : []);
            if ($says === null) {
                continue;
            }

            $id = (int) $row->resolved_employee_id;
            $asked[$id] = ($asked[$id] ?? true) && $says === 'yes';
        }

        return $asked;
    }

    /**
     * @param  Collection<int, Employee>  $people
     * @param  array<int, bool>  $asked
     * @return array<string, bool>
     */
    public function byPublicId(Collection $people, array $asked): array
    {
        $byPublic = [];
        foreach ($people as $person) {
            if (array_key_exists($person->id, $asked)) {
                $byPublic[$person->public_id] = $asked[$person->id];
            }
        }

        return $byPublic;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return 'yes'|'no'|null
     */
    private function says(array $raw): ?string
    {
        $value = $raw[EmployeeImportRow::SIGN_IN] ?? null;

        return match ($value) {
            'yes' => 'yes',
            'no' => 'no',
            default => null,
        };
    }
}
