<?php

namespace App\Support;

use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Models\User;

/**
 * Шаблони за служители (PROJECT.md, „Портал и служители“, 27.11.2026).
 * Шаблонът само попълва правата; после те могат да се сменят поотделно.
 * „Администратор на фирмата“ е отделна отметка.
 */
final class StaffTemplates
{
    /** @return array<string, array{access_level: AccessLevel, property_scope: PropertyScope, can_view_sensitive: bool}> */
    public static function all(): array
    {
        return [
            'broker' => ['access_level' => AccessLevel::Read, 'property_scope' => PropertyScope::Assigned, 'can_view_sensitive' => false],
            'manager' => ['access_level' => AccessLevel::Write, 'property_scope' => PropertyScope::All, 'can_view_sensitive' => false],
            'operator' => ['access_level' => AccessLevel::Write, 'property_scope' => PropertyScope::Assigned, 'can_view_sensitive' => false],
            'accountant' => ['access_level' => AccessLevel::Read, 'property_scope' => PropertyScope::All, 'can_view_sensitive' => true],
        ];
    }

    /** Шаблонът, на който отговарят правата, или null („ръчни права“). */
    public static function of(User $user): ?string
    {
        foreach (self::all() as $key => $rights) {
            if ($user->access_level === $rights['access_level'] && $user->property_scope === $rights['property_scope']
                && (bool) $user->can_view_sensitive === $rights['can_view_sensitive']) {
                return $key;
            }
        }

        return null;
    }
}
