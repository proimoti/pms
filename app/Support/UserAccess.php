<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Спиране и връщане на достъпа до профил (план „Портал и служители“, 27.11.2026).
 * Профилът и данните остават. Спреният не влиза, не получава връзка за нова
 * парола и се изкарва при следващата заявка. Всяка промяна е в журнала.
 */
final class UserAccess
{
    public static function stop(User $user, User $by): void
    {
        self::change($user, $by, true);
    }

    public static function restore(User $user, User $by): void
    {
        self::change($user, $by, false);
    }

    private static function change(User $user, User $by, bool $stop): void
    {
        if ($user->company_id === null) {
            throw new LogicException('Администраторът на приложението не се спира оттук.');
        }
        if ((int) $by->company_id !== (int) $user->company_id) {
            throw new LogicException('Достъпът се спира само от потребител на същата фирма.');
        }
        if ($user->isStopped() === $stop) {
            return; // вече е така
        }

        DB::transaction(function () use ($user, $by, $stop) {
            $old = $user->getRawOriginal('disabled_at');
            $user->forceFill(['disabled_at' => $stop ? now() : null])->save();

            AuditLog::query()->make()->forceFill([
                'company_id' => $user->company_id,
                'user_id' => $by->id,
                'auditable_type' => 'users',
                'auditable_id' => $user->id,
                'event' => $stop ? 'access_stopped' : 'access_restored',
                'changes' => ['disabled_at' => ['old' => $old, 'new' => $user->getRawOriginal('disabled_at')]],
            ])->save();
        });
    }
}
