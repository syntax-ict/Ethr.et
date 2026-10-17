<?php

declare(strict_types=1);

return [
    'no_employee_linked' => 'No employee profile linked to your account.',
    'employee_has_left' => 'This employee has left and cannot record attendance.',
    'no_open_check_in' => 'There is no open check-in to close.',
    'employee_not_found' => 'Employee not found with the given code.',
    'qr_invalid_or_expired' => 'QR code is invalid or has expired.',
    'method_disabled' => 'This attendance method is not enabled for your organization.',
    'geofence_location_required' => 'GPS location is required when geofence enforcement is enabled.',
    'outside_geofence' => 'You are outside the allowed check-in area. Please move closer to your branch location.',
    'conflict_already_resolved' => 'This conflict has already been resolved.',
    'offline_not_own' => 'You can only sync your own attendance.',
    'offline_outside_window' => 'This punch was captured more than :days days ago, or in the future, so it cannot be synced. Ask HR to enter it.',
    'auto_checkout' => [
        'subject' => 'Your attendance was closed at the end of your shift',
        'message' => 'You had not checked out on :date, so your attendance was closed at the end of your shift, :time.',
        'correct_it' => 'If you left at another time, file a correction and your approver will review it.',
        'action' => 'Open corrections',
    ],
    'photo_required' => 'Take a selfie to check in. Your organisation requires a photo when you check in on your phone.',
    'photo_consent_required' => 'Agree to the attendance photo notice before taking a selfie. You can do it from the check-in screen or your profile.',
    'auto_check_in_not_allowed' => 'Automatic check-in is not turned on for you.',
    'auto_check_in_outside' => 'Automatic check-in needs you to be inside your branch area.',
    'device_clock_invalid' => 'The device reported a time that is unreadable or in the future. Check the device clock.',
    'qr_already_used' => 'You have already used this QR code. Scan the current code on the screen.',
    'qr_use_limit_reached' => 'This QR code has been used the maximum number of times. Scan the current code on the screen.',
    'integrity_self_review' => 'You cannot review a flag on your own attendance.',
    'integrity_already_reviewed' => 'This flag has already been reviewed.',
    'punch_rejected' => [
        'subject' => 'A punch of yours was not accepted',
        'message' => 'Your attendance on :date was reviewed and not accepted. Reason: :reason',
        'correct_it' => 'If this is a mistake, file a correction with the right times and your approver will review it.',
        'action' => 'Open corrections',
    ],
];
