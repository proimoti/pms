<?php

namespace App\Services\Statements;

use App\Models\Tenant;
use App\Support\Address;
use Illuminate\Support\Facades\DB;

/**
 * Данните, които известието запомня при издаване (PROJECT.md, „PDF и имейл
 * на известията“, 24.11.2026): фирмата, наемателят и банковата сметка „за
 * известията“ към момента. Чете направо от базата (без филтрите по
 * потребител), защото се вика и от миграцията. ЕИК на наемателя само при
 * юридическо лице; ЕГН и IBAN на хора не се пазят тук.
 *
 * @return array{company: array, tenant: array, bank: ?array}
 */
final class StatementSnapshot
{
    /**
     * Срок за плащане (PROJECT.md, „Вид на известието“, 25.11.2026): 5-о число на
     * месеца на известието; ако е издадено след 5-о число — 5 дни след издаването.
     */
    public static function dueOn(\Carbon\CarbonInterface $month, \Carbon\CarbonInterface $issuedAt): string
    {
        $fifth = \Carbon\CarbonImmutable::create($month->year, $month->month, 5)->startOfDay();
        $issued = \Carbon\CarbonImmutable::parse($issuedAt->format('Y-m-d'))->startOfDay();

        return ($issued->gt($fifth) ? $issued->addDays(5) : $fifth)->format('Y-m-d');
    }

    public function build(int $companyId, ?int $tenantId): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first();
        $tenant = $tenantId === null ? null : DB::table('tenants')->where('company_id', $companyId)->where('id', $tenantId)->first();
        $bank = DB::table('bank_accounts')->where('company_id', $companyId)->whereNull('archived_at')->where('for_statements', true)->first();

        return [
            'company' => [
                'name' => $company?->name, 'eik' => $company?->eik, 'vat_number' => $company?->vat_number,
                'address' => $company?->address, 'email' => $company?->email, 'phone' => $company?->phone,
            ],
            'tenant' => $tenant === null ? ['name' => null, 'kind' => null, 'eik' => null, 'address' => null] : [
                'name' => $tenant->name,
                'kind' => $tenant->kind,
                'eik' => $tenant->kind === 'legal' ? $tenant->eik : null,
                'address' => Address::format((new Tenant)->forceFill((array) $tenant)) ?: null,
            ],
            'bank' => $bank === null ? null : ['bank_name' => $bank->bank_name, 'iban' => $bank->iban, 'bic' => $bank->bic],
        ];
    }
}
