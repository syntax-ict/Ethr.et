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
    'auto_check_in_not_allowed' => 'Automatic check-in is not turned on for you.',
    'auto_check_in_outside' => 'Automatic check-in needs you to be inside your branch area.',
];
