<?php

declare(strict_types=1);

return [
    'enable_requires_verified' => 'Off-host copies can be switched on only after the storage has passed a connection test.',
    'enable_requires_key_saved' => 'Off-host copies can be switched on only after you confirm the encryption key is saved somewhere other than this server.',
    'drive_state_invalid' => 'This sign-in link has expired or was not started here. Start again with Connect Google Drive.',
    'drive_exchange_failed' => 'Google did not accept the sign-in: :reason',
    'drive_no_refresh_token' => 'Google did not give ETHR lasting access. Remove ETHR at myaccount.google.com/permissions, then connect again.',
    'drive_folder_failed' => 'Connected, but the backups folder could not be created in Google Drive: :reason',
    'storage_must_be_in_ethiopia' => 'In production, backups may be stored only in Ethiopia (Proclamation 1321/2024 Art. 22). Confirm that this storage is in Ethiopia, or choose storage that is.',
    'drive_outside_ethiopia' => 'Google Drive stores files outside Ethiopia, so production does not copy backups to it (Proclamation 1321/2024 Art. 22). Archives already on Drive can still be restored.',
];
