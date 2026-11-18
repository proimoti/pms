<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Заявки, които само добавят: без промяна и без триене (и наведнъж). */
class AppendOnlyBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException(__('registry.append_only'));
    }

    public function delete()
    {
        throw new LogicException(__('registry.append_only'));
    }

    public function forceDelete()
    {
        throw new LogicException(__('registry.append_only'));
    }
}
