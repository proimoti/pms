<?php

namespace App\Support;

use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Base\Party;
use App\Models\Owner;
use App\Models\PortalLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Достъп до портала (план „Портал и служители“, 27.11.2026).
 * - „Покани в портала“ от картата на наемател или собственик: имейлът е от
 *   записа; един имейл — един профил с роля „клиент“ и връзки към записите;
 * - достъпът е активен, докато профилът не е спрян и има поне една връзка
 *   към неархивиран запис.
 */
final class PortalAccess
{
    /** @return 'invited'|'linked' */
    public static function invite(Party $party, User $by): string
    {
        if ($party->archived_at !== null) {
            throw new LogicException(__('portal.invite.archived'));
        }
        $email = mb_strtolower(trim((string) $party->email));
        if ($email === '') {
            throw new LogicException(__('portal.invite.no_email'));
        }
        if ((int) $party->company_id !== (int) $by->company_id) {
            throw new LogicException('Покана само за запис на същата фирма.');
        }

        $user = DB::transaction(function () use ($party, $by, $email) {
            $link = self::link($party);
            $user = $link?->user()->withoutGlobalScopes()->first()
                ?? User::query()->withoutGlobalScopes()->where('company_id', $party->company_id)->where('email', $email)->first();

            if ($user !== null && $user->role !== Role::Client) {
                throw new LogicException(__('portal.invite.staff_email'));
            }
            if ($user === null) {
                $user = new User;
                // Паролата е случайна и непозната: клиентът си задава своя по поканата.
                $user->forceFill([
                    'company_id' => $party->company_id,
                    'role' => Role::Client,
                    'name' => $party->name,
                    'email' => $email,
                    'password' => Str::random(64),
                    'access_level' => AccessLevel::Read,
                    'property_scope' => PropertyScope::Assigned,
                    'is_company_admin' => false,
                    'can_view_sensitive' => false,
                ])->save();
                UserAudit::write($user, $by, 'created', ['role' => ['old' => null, 'new' => Role::Client->value], 'email' => ['old' => null, 'new' => $email]]);
            }
            if ($user->isStopped()) {
                throw new LogicException(__('portal.invite.stopped'));
            }
            if ($link === null) {
                PortalLink::query()->create(['user_id' => $user->id] + self::column($party));
            }

            return $user;
        });

        if ($user->invitation_accepted_at !== null) {
            return 'linked';
        }
        Invitation::send($user, $by);

        return 'invited';
    }

    /** Профилът, свързан със записа, или null. */
    public static function userFor(Party $party): ?User
    {
        $link = self::link($party);

        return $link === null ? null : User::query()->withoutGlobalScopes()->find($link->user_id);
    }

    /** @return 'none'|'invited'|'active'|'stopped' */
    public static function state(Party $party): string
    {
        $user = self::userFor($party);

        return match (true) {
            $user === null => 'none',
            $user->isStopped() => 'stopped',
            $user->invitation_accepted_at !== null => 'active',
            default => 'invited',
        };
    }

    /** Активен достъп: поне една връзка към неархивиран наемател или собственик. */
    public static function hasAccess(User $user): bool
    {
        return DB::table('portal_links as l')
            ->where('l.user_id', $user->id)->where('l.company_id', $user->company_id)
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->from('tenants as t')->whereColumn('t.id', 'l.tenant_id')->whereColumn('t.company_id', 'l.company_id')->whereNull('t.archived_at'))
                ->orWhereExists(fn ($s) => $s->from('owners as o')->whereColumn('o.id', 'l.owner_id')->whereColumn('o.company_id', 'l.company_id')->whereNull('o.archived_at')))
            ->exists();
    }

    private static function link(Party $party): ?PortalLink
    {
        return PortalLink::query()->withoutGlobalScopes()->where('company_id', $party->company_id)->where(self::column($party))->first();
    }

    /** @return array{tenant_id: int}|array{owner_id: int} */
    private static function column(Party $party): array
    {
        return $party instanceof Owner ? ['owner_id' => $party->id] : ['tenant_id' => $party->id];
    }
}
