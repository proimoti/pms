<?php

namespace App\Enums;

/** Вид на собственик или наемател. */
enum PartyKind: string
{
    case Individual = 'individual'; // физическо лице
    case Legal = 'legal';           // юридическо лице
}
