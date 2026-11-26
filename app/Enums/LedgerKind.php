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
    case Payout = 'payout';                   // изплащане към собственик (408 −), 26.11.2026
    case PayoutReversal = 'payout_reversal';  // сторно на изплащане (408 +)
    case Opening = 'opening';                 // начално салдо (418, 491, 407 на фонда), 26.11.2026
    case OpeningReversal = 'opening_reversal'; // сторно на началните салда

    /** Видовете, които не са начисления за месеца: плащания, изплащания и начални салда. */
    public static function notAccrual(): array
    {
        return array_merge(self::money(), [self::Opening->value, self::OpeningReversal->value]);
    }

    /** Видовете, които са плащане или изплащане (не начисление). */
    public static function money(): array
    {
        return [self::Payment->value, self::PaymentReversal->value, self::Payout->value, self::PayoutReversal->value];
    }
}
