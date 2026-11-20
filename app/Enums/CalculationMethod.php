<?php

namespace App\Enums;

/** Трите начина на изчисление при договор със собственик (PROJECT.md, т. 3.6). */
enum CalculationMethod: string
{
    case Percent = 'percent'; // процентът е в договора, за всички имоти
    case Fixed = 'fixed';     // сумата е на всеки ред (имот)
    case Own = 'own';         // нищо към собственик
}
