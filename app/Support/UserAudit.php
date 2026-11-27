<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Запис в журнала за профил на потребител (27.11.2026): кой, кога и какво.
 * Профилът не е с Audited, защото паролата, вторият фактор и „запомни ме“ се
 * сменят сами; затова се записва само изрично подаденото, без пароли и връзки.
 */
final class UserAudit
{
    public static function write(User $user, User $by, string $event, array $changes): void
    {
        AuditLog::query()->make()->forceFill([
            'company_id' => $user->company_id,
            'user_id' => $by->id,
            'auditable_type' => 'users',
            'auditable_id' => $user->id,
            'event' => $event,
            'changes' => $changes,
        ])->save();
    }
}
