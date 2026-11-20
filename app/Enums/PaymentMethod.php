<?php

namespace App\Enums;

/** Начин на плащане към бенефициента (PROJECT.md, т. 2 „Договори“). */
enum PaymentMethod: string
{
    case Cash = 'cash'; // в брой
    case Bank = 'bank'; // по банка
}
