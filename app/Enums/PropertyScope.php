<?php

namespace App\Enums;

/** Обхват: кои имоти вижда потребителят. */
enum PropertyScope: string
{
    case All = 'all';           // всички имоти на фирмата
    case Assigned = 'assigned'; // само прикачените
}
