<?php

namespace App\Http\Controllers\Registry;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Общото за екраните на регистъра: списък с търсене и страници по 50,
 * архивиране и връщане от архива. Изтриване няма.
 */
abstract class RegistryController extends Controller
{
    protected const PER_PAGE = 50;

    /** Пътят в адреса, например „properties“. */
    abstract protected function section(): string;

    /** Добавя условията за търсене по текста $term (вече обезопасен за LIKE). */
    abstract protected function applySearch(Builder $query, string $term, string $likeTerm): void;

    /** Списък: активните или архивираните, с търсене. */
    protected function listQuery(Request $request, Builder $query): Builder
    {
        $request->boolean('archived') ? $query->archived() : $query->active();

        $term = trim((string) $request->query('q', ''));

        if ($term !== '') {
            // % и _ се търсят като обикновени знаци.
            $likeTerm = '%'.addcslashes($term, '\\%_').'%';
            $query->where(fn (Builder $inner) => $this->applySearch($inner, $term, $likeTerm));
        }

        return $query;
    }

    protected function paginate(Builder $query)
    {
        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * След запис на нов запис: отваря го; в малкия прозорец „+ Нов“ от формите
     * на договорите вместо това казва на формата кой е (PROJECT.md, 20.11.2026).
     */
    protected function created(Request $request, Model $record, string $label): Response
    {
        if ($request->boolean('popup')) {
            return response()->view('registry.partials.quick-saved', [
                'section' => $this->section(), 'id' => $record->getKey(), 'label' => $label,
            ]);
        }

        return redirect("/{$this->section()}/{$record->getKey()}")->with('status', __('registry.saved'));
    }

    protected function archiveRecord(Model $record): RedirectResponse
    {
        $record->archive();

        return redirect("/{$this->section()}/{$record->getKey()}")->with('status', __('registry.archived'));
    }

    protected function restoreRecord(Model $record): RedirectResponse
    {
        $record->restoreFromArchive();

        return redirect("/{$this->section()}/{$record->getKey()}")->with('status', __('registry.restored'));
    }
}
