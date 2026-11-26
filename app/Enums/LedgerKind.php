<?php

namespace App\Enums;

/** Откъде е записът в книгата. */
enum LedgerKind: string
{
    case Issue = 'issue';           // издаване на месеца или „Издай сега“
    case Correction = 'correction'; // корекция за същия месец (разлика спрямо договорите)
    case Manual = 'manual';         // ръчна корекция с причина
    case UtilityPaid = 'utility_paid'; // внесена платена битова сметка (407 на имота)
    case FundIncome = 'fund_income';   // приход във фонда: фиксирана такса (407 на фонда, −)
    case FundExpense = 'fund_expense'; // разход от фонда: сметка, платена от фонда (407 на фонда, +)
    case ManagerCost = 'manager_cost'; // за сметка на управителя: остатък на сметка (407 на имота, −; + при отмяна)
    case Payment = 'payment';                 // плащане от наемател (418 −), 26.11.2026
    case PaymentReversal = 'payment_reversal'; // сторно на плащане (418 +)
}
