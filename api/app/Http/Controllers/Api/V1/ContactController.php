<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Models\Lead;
use App\Notifications\NewLeadNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The public contact form.
 *
 * This used to be one line — `Log::info('Contact form submission', $data)` —
 * and a 201. Every enquiry from the public site was therefore lost, while the
 * sender was told "we will get back to you within 24 hours", and their name,
 * email and phone were written in plaintext into `storage/logs/laravel.log`.
 *
 * Five tests covered it and all five passed, because all five asserted status
 * codes and validation around a body that threw the data away.
 */
class ContactController extends Controller
{
    public function __invoke(ContactRequest $request): JsonResponse
    {
        // A bot that filled the honeypot gets the same 201 a person gets. Telling
        // it that it failed only teaches it which field to leave alone next time.
        if ($request->looksAutomated()) {
            return $this->accepted();
        }

        // Named explicitly rather than passing `validated()` wholesale.
        // `AppServiceProvider:130` enables `preventSilentlyDiscardingAttributes`
        // outside production, so the day a rule is added for something that is
        // not a `Lead` column, mass-assigning the whole set would throw here
        // instead of being quietly dropped. Listing the five columns costs a
        // line and removes that trap.
        $lead = Lead::create(
            $request->safe()->only(['name', 'email', 'phone', 'organization', 'message']),
        );

        // Persisting is the job; notifying is best-effort on top of it. The
        // notification is queued, so on a worker this rarely throws here — but
        // under QUEUE_CONNECTION=sync it runs inline, and an unreachable SMTP
        // host would then turn a lead that is already safely in the database
        // into a 500 and, most likely, a second submission from a confused
        // sender. Logged at error rather than swallowed: `DispatchesWebhooks`
        // learned that the hard way, where an empty catch hid a real integrity
        // violation for weeks.
        try {
            $inbox = config('mail.contact_inbox');

            if ($inbox) {
                Notification::route('mail', $inbox)
                    ->notify(new NewLeadNotification($lead));
            } else {
                // Not an error: a deployment that has not configured an inbox
                // still captures the lead. Worth a line so it is discoverable
                // when someone asks why no mail arrived.
                Log::info('Lead saved; no contact inbox configured', [
                    'lead' => $lead->public_id,
                ]);
            }
        } catch (\Throwable $e) {
            // The public_id, never the payload — this is the log line that used
            // to carry the sender's email address.
            Log::error('Lead notification failed', [
                'lead' => $lead->public_id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->accepted();
    }

    /**
     * One response shape for every accepted submission, so a bot cannot tell a
     * discarded one from a stored one by comparing them.
     */
    private function accepted(): JsonResponse
    {
        return response()->json([
            'message' => __('general.created', ['resource' => 'Contact request']),
        ], 201);
    }
}
