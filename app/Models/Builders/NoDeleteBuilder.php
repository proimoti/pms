<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Заявки, които не могат да трият записи (и наведнъж). */
class NoDeleteBuilder extends Builder
{
    public function delete()
    {
        throw new LogicException(__('registry.no_delete'));
    }

    public function forceDelete()
    {
        throw new LogicException(__('registry.no_delete'));
    }
}
