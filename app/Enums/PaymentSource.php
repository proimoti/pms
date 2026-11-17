<?php

namespace App\Enums;

/** Единственият източник на плащания за фирмата (PROJECT.md, т. 5.1). */
enum PaymentSource: string
{
    case Manual = 'manual';
    case BankFile = 'bank_file';
    case Epay = 'epay';
    case MicroinvestExcel = 'microinvest_excel';
    case Agent = 'agent';
}
