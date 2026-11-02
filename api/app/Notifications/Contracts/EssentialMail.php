<?php

declare(strict_types=1);

namespace App\Notifications\Contracts;

/**
 * A notification someone is waiting for to get into their account: a sign-in
 * code, a password reset, an activation link. Never held by the day's send
 * cap (MailBudget); the rest of the day's mail stops early to leave it room.
 */
interface EssentialMail {}
