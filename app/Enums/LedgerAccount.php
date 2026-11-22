<?php

namespace App\Enums;

/** Трите салда в книгата (PROJECT.md, т. 4 и т. 6). */
enum LedgerAccount: string
{
    case Tenant = '418';  // разчети с наемател
    case Deposit = '491'; // депозит на наемател
    case Owner = '408';   // разчети със собственик
    case Utility = '407'; // битови сметки, по имот (платено +, начислено на наемател −)
}
