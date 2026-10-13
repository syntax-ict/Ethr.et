<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceCommandType: string
{
    case SET_TIME = 'set_time';
    case UPSERT_USER = 'upsert_user';
    case DELETE_USER = 'delete_user';
}
