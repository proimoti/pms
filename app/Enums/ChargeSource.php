<?php

namespace App\Enums;

/** Откъде е редът в работната таблица. */
enum ChargeSource: string
{
    case Computed = 'computed'; // от договорите (сметката)
    case Upcoming = 'upcoming'; // от предстоящо начисление
    case Manual = 'manual';     // добавен на ръка
}
