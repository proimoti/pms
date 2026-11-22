<?php

namespace App\Services\Utilities;

use App\Enums\UtilityBilling;
use App\Enums\UtilityKind;
use App\Models\Property;
use App\Models\Provider;
use App\Models\UtilityAccount;
use App\Models\UtilityAccountProperty;
use App\Models\Scopes\CompanyScope;
use App\Support\BgDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

/**
 * Запис на абонат (PROJECT.md, т. 3.4 „Битови сметки, уточнения“, т. 5.3,
 * 21.11.2026). Всички грешки излизат наведнъж и при грешка не се записва нищо.
 *
 * - реален: доставчик и номер задължителни, без фиксирана сума;
 * - фиксиран: сума над 0; номер и доставчик по избор;
 * - номерът се пази точно; доставчик + номер (без нулите отпред) е уникален
 *   във фирмата, и сред архивираните (абонат не минава към друг имот);
 * - период: ден от (празно = 1) и ден до (празно = последният ден), 1–31;
 * - поне един имот; при един имот делът е 100 %, при няколко всеки е над 0,
 *   с до два знака, и сборът не е над 100 %; остатъкът отива към последния.
 */
final class UtilityAccountService
{
    public function create(array $data): UtilityAccount
    {
        return $this->save(new UtilityAccount, $data);
    }

    public function update(UtilityAccount $account, array $data): UtilityAccount
    {
        return $this->save($account, $data);
    }

    private function save(UtilityAccount $account, array $data): UtilityAccount
    {
        $errors = new MessageBag;
        $values = $this->values($account, $data, $errors);
        $lines = $this->lines($data['properties'] ?? [], $errors);

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }

        return DB::transaction(function () use ($account, $values, $lines) {
            $account->fill($values);
            if (isset($values['company_id'])) {
                $account->company_id = $values['company_id'];
            }
            $account->save();

            // Имотите се записват наново (старите редове отиват в журнала като махнати).
            foreach ($account->properties()->get() as $old) {
                $old->delete();
            }
            foreach ($lines as $position => [$propertyId, $share]) {
                $line = new UtilityAccountProperty([
                    'utility_account_id' => $account->id, 'property_id' => $propertyId,
                    'share' => $share, 'position' => $position + 1,
                ]);
                $line->company_id = $account->company_id;
                $line->save();
            }

            return $account->fresh();
        });
    }

    /** Полетата на абоната; грешките отиват в $errors. */
    private function values(UtilityAccount $account, array $data, MessageBag $errors): array
    {
        $kind = UtilityKind::tryFrom((string) ($data['kind'] ?? ''));
        $billing = UtilityBilling::tryFrom((string) ($data['billing'] ?? ''));
        $kind ?? $errors->add('kind', __('utilities.errors.kind'));
        $billing ?? $errors->add('billing', __('utilities.errors.billing'));
        $fixed = $billing === UtilityBilling::Fixed;

        // Доставчик
        $providerId = $this->blank($data['provider_id'] ?? null) ? null : $data['provider_id'];
        if ($providerId !== null && ! Provider::query()->whereKey($providerId)->exists()) {
            $errors->add('provider_id', __('utilities.errors.provider_not_found'));
            $providerId = null;
        } elseif ($providerId === null && $billing === UtilityBilling::Actual) {
            $errors->add('provider_id', __('utilities.errors.provider_required'));
        }

        // Номер
        $number = $this->blank($data['number'] ?? null) ? null : trim((string) $data['number']);
        if ($number === null && $billing === UtilityBilling::Actual) {
            $errors->add('number', __('utilities.errors.number_required'));
        } elseif ($number !== null && ! preg_match('/^[\p{L}\d\-\/.]{1,50}$/u', $number)) {
            $errors->add('number', __('utilities.errors.number_format'));
        } elseif ($number !== null && $providerId !== null && $this->numberTaken($account, $this->companyId($account, $data), (int) $providerId, $number)) {
            $errors->add('number', __('utilities.errors.number_taken', ['number' => $number]));
        }

        // Фиксирана сума
        $amount = null;
        if ($fixed) {
            $amount = $this->money($data['fixed_amount'] ?? null);
            $amount ?? $errors->add('fixed_amount', __('utilities.errors.fixed_amount'));
        }

        // Период на потребление
        $start = $this->day($data['period_start_day'] ?? null, 1);
        $end = $this->day($data['period_end_day'] ?? null, null);
        $start === false && $errors->add('period_start_day', __('utilities.errors.day'));
        $end === false && $errors->add('period_end_day', __('utilities.errors.day'));
        if (is_int($start) && is_int($end) && $end < $start) {
            $errors->add('period_end_day', __('utilities.errors.period_order'));
        }

        // Дати на действие
        $from = BgDate::parse((string) ($data['valid_from'] ?? ''));
        $from ?? $errors->add('valid_from', __('utilities.errors.valid_from'));
        $to = null;
        if (! $this->blank($data['valid_to'] ?? null)) {
            $to = BgDate::parse((string) $data['valid_to']);
            if ($to === null) {
                $errors->add('valid_to', __('utilities.errors.date'));
            } elseif ($from !== null && $to->lt($from)) {
                $errors->add('valid_to', __('utilities.errors.valid_to_order'));
            }
        }

        $values = [
            'provider_id' => $providerId,
            'number' => $number,
            'kind' => $kind,
            'billing' => $billing,
            'fixed_amount' => $amount,
            'period_start_day' => is_int($start) ? $start : 1,
            'period_end_day' => is_int($end) ? $end : null,
            'valid_from' => $from?->toDateString(),
            'valid_to' => $to?->toDateString(),
            'note' => $this->blank($data['note'] ?? null) ? null : trim((string) $data['note']),
        ];

        if (isset($data['company_id'])) {
            $values['company_id'] = (int) $data['company_id']; // команди на сървъра (без влязъл потребител)
        }

        return $values;
    }

    /**
     * Имотите със съотношение: [[property_id, share], ...] по реда им.
     * Празните редове от формата се прескачат.
     */
    private function lines(array $rows, MessageBag $errors): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => is_array($row) && ! $this->blank($row['property_id'] ?? null)));

        if ($rows === []) {
            $errors->add('properties', __('utilities.errors.properties_required'));

            return [];
        }

        $ids = array_map(fn ($row) => (int) $row['property_id'], $rows);
        if (count(array_unique($ids)) !== count($ids)) {
            $errors->add('properties', __('utilities.errors.property_twice'));
        }

        // Имот на фирмата, неархивиран и видим за потребителя.
        $found = Property::query()->whereNull('archived_at')->whereKey($ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($ids, $found) !== []) {
            $errors->add('properties', __('utilities.errors.property_not_found'));
        }

        if (count($rows) === 1) {
            return [[$ids[0], '100.00']];
        }

        $lines = [];
        $total = 0;
        foreach ($rows as $index => $row) {
            $share = $this->share($row['share'] ?? null);
            if ($share === null) {
                $errors->add('properties', __('utilities.errors.share'));
            } else {
                $total += (int) round(((float) $share) * 100);
            }
            $lines[] = [$ids[$index], $share];
        }

        if ($total > 10000) {
            $errors->add('properties', __('utilities.errors.shares_over_100'));
        }

        return $lines;
    }

    /** Фирмата на абоната: на влезлия потребител, или подадената (команди на сървъра). */
    private function companyId(UtilityAccount $account, array $data): ?int
    {
        return CompanyScope::restrictedCompanyId() ?? $account->company_id ?? (isset($data['company_id']) ? (int) $data['company_id'] : null);
    }

    private function numberTaken(UtilityAccount $account, ?int $companyId, int $providerId, string $number): bool
    {
        return UtilityAccount::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('provider_id', $providerId)
            ->where('number_key', UtilityAccount::numberKey($number))
            ->when($account->exists, fn ($query) => $query->whereKeyNot($account->id))
            ->exists();
    }

    /** Ден от месеца 1–31; празно = $default; false при грешка. */
    private function day($value, ?int $default): int|false|null
    {
        if ($this->blank($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return preg_match('/^\d{1,2}$/', $value) && (int) $value >= 1 && (int) $value <= 31 ? (int) $value : false;
    }

    /** Сума над 0 с до два знака (запетая или точка); null при грешка. */
    private function money($value): ?string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,10}([.,]\d{1,2})?$/', $value)) {
            return null;
        }

        $number = (float) str_replace(',', '.', $value);

        return $number > 0 ? number_format($number, 2, '.', '') : null;
    }

    /** Дял в проценти: над 0 и до 100, с до два знака; null при грешка. */
    private function share($value): ?string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $value)) {
            return null;
        }

        $number = (float) str_replace(',', '.', $value);

        return $number > 0 && $number <= 100 ? number_format($number, 2, '.', '') : null;
    }

    private function blank($value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
