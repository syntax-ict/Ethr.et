<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'organization' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],

            // The honeypot is deliberately absent — see `looksAutomated()`.
        ];
    }

    /**
     * Whether this submission looks like a bot rather than a person.
     *
     * `throttle:auth` caps the endpoint at 10 requests a minute per IP, which is
     * rate limiting, not bot defence: it does nothing about a single spammer
     * posting nine times a minute, forever. That mattered less when submissions
     * went to a log file nobody read. It matters now that they are rows.
     *
     * The `website` field is read here rather than declared in `rules()`, for
     * two reasons. The obvious one: Scramble derives the public OpenAPI schema
     * from those rules, so declaring it would publish the trap's field name in
     * the API contract — and a bot author reading the spec would know exactly
     * which field to leave empty. The second: it keeps `ContactRequest` in the
     * generated contract unchanged, so an anti-spam detail does not churn a file
     * the frontend type-checks against.
     *
     * Not validated, because nothing is done with the value beyond asking
     * whether it is empty, and it is never stored. Request size is already
     * bounded upstream.
     *
     * A honeypot costs nothing and needs no third party — which matters on a
     * site claiming no data leaves the country — and catches the naive
     * form-filler that makes up the bulk of this traffic. It is not a CAPTCHA
     * and does not pretend to be: a bot written against this specific form will
     * still get through, and the answer to that is moderation on the read side,
     * whenever a read side exists.
     */
    public function looksAutomated(): bool
    {
        return filled($this->input('website'));
    }
}
