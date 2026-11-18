<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Записва всяко създаване, промяна, архивиране и връщане от архива в журнала.
 *
 * Полетата в $auditProtected (например IBAN) се отбелязват като сменени,
 * но стойностите им не се записват.
 */
trait Audited
{
    /** Полета, които не се записват в журнала: id, фирмата и служебните дати. */
    private static array $auditSkipped = ['id', 'company_id', 'created_at', 'updated_at'];

    public static function bootAudited(): void
    {
        static::created(function (Model $model) {
            $changes = [];
            foreach ($model->getAttributes() as $field => $value) {
                $changes[$field] = ['old' => null, 'new' => $value];
            }

            $model->writeAudit('created', $changes);
        });

        static::updated(function (Model $model) {
            $changes = [];
            foreach ($model->getChanges() as $field => $value) {
                $changes[$field] = ['old' => $model->getRawOriginal($field), 'new' => $value];
            }

            $event = match (true) {
                array_key_exists('archived_at', $changes) && $changes['archived_at']['new'] !== null => 'archived',
                array_key_exists('archived_at', $changes) => 'restored',
                default => 'updated',
            };

            $model->writeAudit($event, $changes);
        });

        // Само за записи, които могат да се махат (например прикачен имот).
        // Записите на регистъра не се трият изобщо.
        static::deleted(function (Model $model) {
            $changes = [];
            foreach ($model->getRawOriginal() as $field => $value) {
                $changes[$field] = ['old' => $value, 'new' => null];
            }

            $model->writeAudit('deleted', $changes);
        });
    }

    protected function writeAudit(string $event, array $changes): void
    {
        $changes = array_diff_key($changes, array_flip(self::$auditSkipped));

        if ($changes === []) {
            return;
        }

        $protected = property_exists($this, 'auditProtected') ? $this->auditProtected : [];
        foreach ($protected as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = ['old' => '[скрито]', 'new' => '[скрито]'];
            }
        }

        AuditLog::query()->make()->forceFill([
            'company_id' => $this->company_id,
            'user_id' => Auth::hasUser() ? Auth::id() : null,
            'auditable_type' => $this->getTable(),
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'changes' => $changes,
        ])->save();
    }
}
