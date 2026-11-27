<?php

namespace App\Enums;

enum Role: string
{
    case AppAdmin = 'app_admin'; // администратор на приложението, без фирма
    case Staff = 'staff';        // служител на фирма за управление
    case Owner = 'owner';        // собственик
    case Tenant = 'tenant';      // наемател
    case Client = 'client';      // профил в портала (27.11.2026); какво вижда, решават връзките
}
