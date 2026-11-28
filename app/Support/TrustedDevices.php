<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Запомнени устройства за портала (27.11.2026): след вярен код по имейл
 * устройството се помни 180 дни (28.11.2026) и при вход от него код не се иска. В базата
 * е само отпечатъкът (sha256) на бисквитката. При спиране на достъпа всички
 * устройства на профила се забравят.
 */
final class TrustedDevices
{
    public const COOKIE = 'portal_device';

    public const DAYS = 180;

    public static function isTrusted(User $user, Request $request): bool
    {
        $token = (string) $request->cookie(self::COOKIE);
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return false;
        }

        return DB::table('trusted_devices')->where('user_id', $user->id)->where('company_id', $user->company_id)
            ->where('token', hash('sha256', $token))->where('expires_at', '>', now())->exists();
    }

    public static function remember(User $user): Cookie
    {
        $token = Str::random(64);
        DB::table('trusted_devices')->insert([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::DAYS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return cookie(self::COOKIE, $token, self::DAYS * 24 * 60, secure: request()->isSecure(), httpOnly: true, sameSite: 'lax');
    }

    public static function forget(User $user): void
    {
        DB::table('trusted_devices')->where('user_id', $user->id)->delete();
    }
}
