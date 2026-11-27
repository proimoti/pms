<?php

namespace App\Support;

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
            if ($stop) {
                TrustedDevices::forget($user); // запомнените устройства за портала (27.11.2026)
            }

            UserAudit::write($user, $by, $stop ? 'access_stopped' : 'access_restored',
                ['disabled_at' => ['old' => $old, 'new' => $user->getRawOriginal('disabled_at')]]);
        });
    }
}
