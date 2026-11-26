<?php

namespace App\Services\Utilities;

use App\Models\Fund;
use App\Models\FundProperty;
use App\Models\Property;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\UtilityAccountProperty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

/**
 * Запис на общ фонд (PROJECT.md, „Общ фонд“, решено 22.11.2026, уточнено
 * 23.11.2026). Всички грешки излизат наведнъж и при грешка не се записва нищо.
 *
 * - име: задължително, уникално във фирмата (и сред архивираните);
 * - аналитика за Микроинвест: задължителна, уникална във фирмата (фондовете
 *   от обновяването са без нея и показват предупреждение, докато не се
 *   попълни при първата промяна);
 * - поне един имот (фондът може да е и за един имот), активен и на фирмата;
 * - имот, който е на неархивиран абонат на фонда, не може да излезе от него.
 */
final class FundService
{
    public function create(array $data): Fund
    {
        return $this->save(new Fund, $data);
    }

    public function update(Fund $fund, array $data): Fund
    {
        return $this->save($fund, $data);
    }

    private function save(Fund $fund, array $data): Fund
    {
        $errors = new MessageBag;
        $companyId = CompanyScope::restrictedCompanyId() ?? $fund->company_id ?? (isset($data['company_id']) ? (int) $data['company_id'] : null);

        $name = $this->text($data['name'] ?? null, 255);
        // Номерът (аналитиката за Микроинвест, 26.11.2026): празно = следващият; само цифри от 1.
        $numberText = trim((string) ($data['number'] ?? ''));
        $number = null;
        $note = $this->text($data['note'] ?? null, null);

        if ($name === null) {
            $errors->add('name', __('utilities.errors.fund_name_required'));
        } elseif ($this->taken($fund, $companyId, 'name', $name)) {
            $errors->add('name', __('utilities.errors.fund_name_taken'));
        }
        if ($numberText !== '') {
            if (! ctype_digit($numberText) || (int) $numberText < 1) {
                $errors->add('number', __('utilities.errors.fund_number_invalid'));
            } else {
                $number = (int) $numberText;
                if ($fund->exists && $number !== (int) $fund->number && $fund->isNumberLocked()) {
                    $errors->add('number', __('utilities.errors.fund_number_locked'));
                } elseif ($this->taken($fund, $companyId, 'number', $number)) {
                    $errors->add('number', __('registry.number_taken', ['number' => $number]));
                }
            }
        }

        $propertyIds = $this->properties($data['properties'] ?? [], $errors);
        if ($fund->exists) {
            $this->keepAccountProperties($fund, $propertyIds, $errors);
        }

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }

        return DB::transaction(function () use ($fund, $companyId, $name, $number, $note, $propertyIds) {
            $fund->fill(['name' => $name, 'note' => $note]);
            if ($number !== null) {
                $fund->number = $number;
            }
            if (! $fund->exists && $companyId !== null) {
                $fund->company_id = $companyId;
            }
            $fund->save();

            // Махнатите имоти се изтриват от списъка (в журнала), новите се добавят.
            $current = FundProperty::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('company_id', $fund->company_id)->where('fund_id', $fund->id)->get();
            foreach ($current as $line) {
                if (! in_array((int) $line->property_id, $propertyIds, true)) {
                    $line->delete();
                }
            }
            $kept = $current->pluck('property_id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff($propertyIds, $kept) as $propertyId) {
                $line = new FundProperty(['fund_id' => $fund->id, 'property_id' => $propertyId]);
                $line->company_id = $fund->company_id;
                $line->save();
            }

            return $fund->fresh();
        });
    }

    /** @return list<int> имотите (празните редове се прескачат) */
    private function properties($rows, MessageBag $errors): array
    {
        $ids = array_values(array_map('intval', array_filter((array) $rows, fn ($id) => is_scalar($id) && trim((string) $id) !== '')));

        if ($ids === []) {
            $errors->add('properties', __('utilities.errors.fund_properties_required'));

            return [];
        }
        if (count(array_unique($ids)) !== count($ids)) {
            $errors->add('properties', __('utilities.errors.property_twice'));
        }

        // Имот на фирмата, неархивиран и видим за потребителя.
        $found = Property::query()->whereNull('archived_at')->whereKey($ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($ids, $found) !== []) {
            $errors->add('properties', __('utilities.errors.property_not_found'));
        }

        return array_values(array_unique($ids));
    }

    /** Имотите на неархивираните абонати на фонда остават във фонда. */
    private function keepAccountProperties(Fund $fund, array $propertyIds, MessageBag $errors): void
    {
        $used = UtilityAccountProperty::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $fund->company_id)
            ->whereIn('utility_account_id', fn ($q) => $q->select('id')->from('utility_accounts')
                ->where('company_id', $fund->company_id)->where('fund_id', $fund->id)->whereNull('archived_at'))
            ->pluck('property_id')->map(fn ($id) => (int) $id)->unique()->all();

        $leaving = array_diff($used, $propertyIds);
        if ($leaving !== []) {
            $numbers = Property::query()->withoutGlobalScopes()->whereKey($leaving)->orderBy('number')->pluck('number')->implode(', ');
            $errors->add('properties', __('utilities.errors.fund_property_in_use', ['numbers' => $numbers]));
        }
    }

    private function taken(Fund $fund, ?int $companyId, string $field, string|int $value): bool
    {
        return Fund::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->where($field, $value)
            ->when($fund->exists, fn ($q) => $q->whereKeyNot($fund->id))
            ->exists();
    }

    /** Текст без интервалите в краищата; празно = няма; $max знака най-много. */
    private function text($value, ?int $max): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $max === null ? $value : mb_substr($value, 0, $max);
    }
}
