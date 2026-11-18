<?php

namespace App\Models\Concerns;

use App\Models\Builders\NoDeleteBuilder;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Записите не се трият, само се архивират (PROJECT.md, т. 2 „Регистър“).
 * Таблицата има колона archived_at.
 */
trait Archivable
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

    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function restoreFromArchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('archived_at'));
    }

    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('archived_at'));
    }

    public function initializeArchivable(): void
    {
        $this->mergeCasts(['archived_at' => 'datetime']);
    }
}
