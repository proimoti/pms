<?php

namespace App\Models\Concerns;

use App\Models\Builders\NoDeleteBuilder;
use LogicException;

/** Записите не се трият нито един по един, нито наведнъж. */
trait NeverDeleted
{
    public function newEloquentBuilder($query): NoDeleteBuilder
    {
        return new NoDeleteBuilder($query);
    }

    public function delete()
    {
        throw new LogicException(__('registry.no_delete'));
    }

    public function forceDelete()
    {
        throw new LogicException(__('registry.no_delete'));
    }
}
