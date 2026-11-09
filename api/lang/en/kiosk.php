<?php

declare(strict_types=1);

return [
    'token_required' => 'Kiosk session token is required.',
    'invalid_token' => 'Invalid or inactive kiosk session.',
    'code_ambiguous' => 'This number matches more than one person. Type your full code with the ABC key.',
    'pin_required' => 'Employee PIN is required for kiosk check-in.',
    'invalid_pin' => 'Invalid employee PIN.',
    'invalid_admin_pin' => 'Incorrect admin PIN.',
    'pin_locked' => 'Too many wrong PINs for this employee. Try again in 15 minutes or ask HR.',
    'offline_unreadable' => 'This offline punch could not be read. Punch again.',
    'offline_failed' => 'This offline punch could not be recorded. Ask HR to enter it.',
    'offline_switched_off' => 'Kiosk check-in is switched off, and this offline punch cannot be shown to have been made before it was. Ask HR to enter it.',
    'self_service_refused' => 'That code and PIN do not match. Check both and try again.',
    'self_service_no_pin' => 'You do not have a kiosk PIN yet. Ask HR to set one.',
    'self_service_expired' => 'Your session at this kiosk has ended. Enter your code and PIN again.',
    'report_too_late' => 'This punch can no longer be reported here. Tell HR.',
];
