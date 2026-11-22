<?php

namespace App\Enums;

/** Откъде е записът в книгата. */
enum LedgerKind: string
{
    case Issue = 'issue';           // издаване на месеца или „Издай сега“
    case Correction = 'correction'; // корекция за същия месец (разлика спрямо договорите)
    case Manual = 'manual';         // ръчна корекция с причина
    case UtilityPaid = 'utility_paid'; // внесена платена битова сметка (407)
}
