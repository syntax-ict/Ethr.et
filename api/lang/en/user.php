<?php

declare(strict_types=1);

return [
    'activation' => [
        'subject' => 'Set up your :org account',
        'greeting' => 'Welcome to ETHR',
        'intro' => 'An account has been created for you at :org. Click the button below to set your password and activate your account.',
        'action' => 'Activate Account',
        'expiry' => 'This link will expire in 60 minutes.',
        'ignore' => 'If you were not expecting this invitation, you can safely ignore this email.',
    ],
    'invited' => ':count user(s) invited.',
    'invite_resent' => 'Activation link resent.',
    'created' => 'User account created.',
    'updated' => 'User updated.',
    'deactivated' => 'User deactivated.',
    'errors' => [
        'role_not_assignable' => 'This role cannot be assigned.',
        'role_above_your_level' => 'You cannot assign a role higher than your own.',
        'cannot_modify_higher' => 'You cannot modify a user with a higher role than your own.',
        'cannot_delete_self' => 'You cannot deactivate your own account.',
        'not_pending' => 'This user is not pending activation.',
        'already_exists' => 'A user with this email already exists.',
    ],
];
