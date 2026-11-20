<?php

namespace App\Models\Builders;

use LogicException;

/** Заявки, които само добавят: без обща промяна и без изтриване. */
class AppendOnlyBuilder extends NoDeleteBuilder
{
    public function update(array $values)
    {
        throw new LogicException(__('charges.messages.append_only'));
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException(__('charges.messages.append_only'));
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException(__('charges.messages.append_only'));
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException(__('charges.messages.append_only'));
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
