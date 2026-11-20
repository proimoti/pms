<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Записите не се трият, само се архивират (PROJECT.md, т. 2 „Регистър“).
 * Таблицата има колона archived_at.
 */
trait Archivable
{
    use NeverDeleted;

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
