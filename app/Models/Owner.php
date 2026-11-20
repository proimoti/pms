<?php

namespace App\Models;

use App\Enums\PartyKind;
use App\Models\Base\Party;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Database\Factories\OwnerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Validation\ValidationException;

/** Собственик (сметка 408 в счетоводството). */
#[UseFactory(OwnerFactory::class)]
class Owner extends Party
{
    protected $table = 'owners';

    protected static function booted(): void
    {
        // При „Преотдаване, физическо/юридическо лице“ видът на собственика
        // трябва да съвпада с типа (PROJECT.md, т. 2 „Договори, уточнения“).
        static::updating(function (Owner $owner) {
            if (! $owner->isDirty('kind')) {
                return;
            }

            $conflict = OwnerContract::query()->withoutGlobalScopes()
                ->where('company_id', $owner->company_id)
                ->where('owner_id', $owner->id)
                ->whereNull('archived_at')
                ->get()
                ->first(fn (OwnerContract $c) => ($kind = $c->type->requiredOwnerKind()) !== null && $kind !== $owner->kind);

            if ($conflict !== null) {
                throw ValidationException::withMessages(['kind' => __('contracts.messages.owner_kind_locked', [
                    'type' => __('contracts.types.'.$conflict->type->value),
                ])]);
            }
        });
    }

    /** Обхват „само прикачените имоти“: вижда се, ако е собственик във видим договор за управление. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::referencedByContracts('owner_contracts', 'owner_id', 'owner_contract_lines', 'owner_contract_id');
    }
}
