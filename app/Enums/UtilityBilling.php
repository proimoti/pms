<?php

namespace App\Enums;

/**
 * Реален или фиксиран абонат (PROJECT.md, т. 3.4): реален = начислява се
 * платеното; фиксиран = постоянна месечна сума, по дни като наема, без
 * съпоставка с плащане.
 */
enum UtilityBilling: string
{
    case Actual = 'actual';
    case Fixed = 'fixed';
}
