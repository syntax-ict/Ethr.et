<?php

declare(strict_types=1);

namespace App\Notifications\Contracts;

/**
 * A notification that one action or one scheduled scan sends to many people at
 * once: payslips released, a morning's missing punches. Its email stops at the
 * bulk share of the day's send cap (MailBudget); the in-app notice still lands.
 */
interface BulkMail {}
