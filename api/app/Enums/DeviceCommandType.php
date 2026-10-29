<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceCommandType: string
{
    case SET_TIME = 'set_time';
    case UPSERT_USER = 'upsert_user';
    case DELETE_USER = 'delete_user';

    /**
     * Ask the device who is enrolled on it. Changes nothing on the device, so
     * it is the one command sent without `commands_enabled`. ZKTeco ADMS only.
     */
    case QUERY_USERS = 'query_users';
}
