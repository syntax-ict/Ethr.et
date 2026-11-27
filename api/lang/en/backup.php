<?php

declare(strict_types=1);

return [
    'run_already_running' => 'A backup or another maintenance task is already running. Wait for it to finish, then try again.',
    'enable_requires_verified' => 'Off-host copies can be switched on only after the storage has passed a connection test.',
    'enable_requires_key_saved' => 'Off-host copies can be switched on only after you confirm the encryption key is saved somewhere other than this server.',
    'drive_state_invalid' => 'This sign-in link has expired or was not started here. Start again with Connect Google Drive.',
    'drive_exchange_failed' => 'Google did not accept the sign-in: :reason',
    'drive_no_refresh_token' => 'Google did not give ETHR lasting access. Remove ETHR at myaccount.google.com/permissions, then connect again.',
    'drive_folder_failed' => 'Connected, but the backups folder could not be created in Google Drive: :reason',
];
