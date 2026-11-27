<?php

namespace App\Support;

use App\Models\CompanyDomain;
use Illuminate\Http\Request;

/**
 * Фирмата по адреса (PROJECT.md, „Портал и служители“, 27.11.2026): адресът
 * (домейн или поддомейн), на който се влиза, определя фирмата. Отделният адрес
 * от настройката pms.admin_host е за администратора на приложението.
 */
final class CompanyAddress
{
    /** Адресът на администратора на приложението ли е. */
    public static function isAdmin(Request $request): bool
    {
        $admin = (string) config('pms.admin_host');

        return $admin !== '' && CompanyDomain::normalize($admin) === CompanyDomain::normalize($request->getHost());
    }

    /** Фирмата на адреса или null (непознат адрес или адресът на администратора). */
    public static function companyId(Request $request): ?int
    {
        if (self::isAdmin($request)) {
            return null;
        }
        $host = CompanyDomain::normalize($request->getHost());

        $key = "company_address.{$host}";
        if (! $request->attributes->has($key)) {
            // Без филтъра по фирма: адресът се търси и преди вход, и при влязъл от друга фирма.
            $id = CompanyDomain::query()->withoutGlobalScopes()->where('host', $host)->value('company_id');
            $request->attributes->set($key, $id === null ? null : (int) $id);
        }

        return $request->attributes->get($key);
    }

    /** Ограничава търсенето на потребител до профилите на адреса; непознат адрес — никого. */
    public static function constrain($query, Request $request)
    {
        if (self::isAdmin($request)) {
            return $query->whereNull('company_id')->where('role', \App\Enums\Role::AppAdmin->value);
        }
        $companyId = self::companyId($request);

        return $companyId === null ? $query->whereRaw('1 = 0') : $query->where('company_id', $companyId);
    }
}
