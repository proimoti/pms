<?php

namespace App\Enums;

/** Видът на абоната (PROJECT.md, т. 3.4 „Битови сметки, уточнения“). */
enum UtilityKind: string
{
    case Electricity = 'electricity'; // електроенергия
    case Water = 'water';             // вода
    case Heating = 'heating';         // топлофикация
    case Gas = 'gas';                 // газ
    case Internet = 'internet';       // интернет
    case Common = 'common';           // общи нужди
    case Other = 'other';             // други
}
