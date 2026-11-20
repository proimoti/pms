<?php

namespace App\Models\Concerns;

use App\Models\Builders\AppendOnlyBuilder;
use LogicException;

/**
 * Само добавяне (CLAUDE.md, правила за безопасност): записът не се променя и
 * не се трие — нито един по един, нито с обща заявка. Грешките се поправят с
 * нов (коригиращ) запис.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function () {
            throw new LogicException(__('charges.messages.append_only'));
        });
    }

    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    public function delete()
    {
        throw new LogicException(__('charges.messages.append_only'));
    }

    public function forceDelete()
    {
        throw new LogicException(__('charges.messages.append_only'));
    }
}
