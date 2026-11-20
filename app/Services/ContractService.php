<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Общото за договор за управление и договор за наем (PROJECT.md, т. 2
 * „Договори“, „Договори, уточнения“, „Дати на договорите“).
 *
 * Анексът не редактира стария вариант: затваря го („до“ = датата на промяната)
 * и добавя нов от тази дата. Всяка операция е в една транзакция: при отказ
 * не остава нищо. Защитата „един имот в един договор от този вид за един
 * период“ е тук: имотът се заключва в базата, докато трае проверката.
 */
abstract class ContractService
{
    /** Класът на заглавието (OwnerContract, LeaseContract). */
    abstract protected function contractClass(): string;

    /** Класът на анекса. */
    abstract protected function annexClass(): string;

    /** Колоната, с която редовете и анексите сочат договора. */
    abstract protected function contractKey(): string;

    /** Съобщението при имот, зает от друг договор от същия вид. */
    abstract protected function busyMessage(): string;

    /** Допълнителни колони, които всеки вариант копира от договора. */
    protected function versionAttributes(Model $contract): array
    {
        return [];
    }

    public function addAnnex(Model $contract, int $number, $signedOn, ?string $note = null): Model
    {
        return DB::transaction(function () use ($contract, $number, $signedOn, $note) {
            $class = $this->annexClass();
            $taken = $class::query()->withoutGlobalScopes()
                ->where('company_id', $contract->company_id)
                ->where($this->contractKey(), $contract->id)
                ->where('number', $number)
                ->exists();

            if ($number < 1 || $taken) {
                $this->reject('number', 'annex_number_taken', ['number' => $number]);
            }

            $annex = new $class;
            $annex->forceFill([
                'company_id' => $contract->company_id,
                $this->contractKey() => $contract->id,
                'number' => $number,
                'signed_on' => $this->date($signedOn, 'signed_on', 'signed_on_required'),
                'note' => $note,
            ])->save();

            return $annex;
        });
    }

    /** Маха имот от договора от дадена дата (от нея нататък не е в договора). */
    public function removeProperty(Model $annex, Property|int $property, $from): Model
    {
        return DB::transaction(function () use ($annex, $property, $from) {
            $contract = $this->contractOf($annex);
            $current = $this->openLine($contract, $property);
            $from = $this->changeDate($contract, $current, $from);

            $current->forceFill(['valid_to' => $from, 'closed_by_annex_id' => $annex->id])->save();

            return $current;
        });
    }

    /** Проверява всички имоти на договора (след прекратяване, отмяна, връщане от архива). */
    public function ensurePropertiesAreFree(Model $contract): void
    {
        $ids = $contract->lines()->withoutGlobalScopes()
            ->where('company_id', $contract->company_id)
            ->distinct()->orderBy('property_id')->pluck('property_id');

        foreach ($ids as $id) {
            $this->ensurePropertyIsFree($contract->company_id, (int) $id, $contract);
        }
    }

    /**
     * Един имот не може да е в два договора от един вид (нито два пъти в един)
     * за един и същи период. Архивираните договори не се броят. Периодът на ред
     * свършва с „до“ на реда или с прекратяването на договора, което е по-рано.
     */
    public function ensurePropertyIsFree(int $companyId, int $propertyId, Model $current): void
    {
        // Заключва имота до края на транзакцията: два записа едновременно чакат един друг.
        $property = DB::table('properties')->where('company_id', $companyId)->where('id', $propertyId)->lockForUpdate()->first();

        $contracts = $current->getTable();
        $lines = $current->lines()->getRelated()->getTable();
        $key = $this->contractKey();

        $periods = DB::table("{$lines} as l")
            ->join("{$contracts} as c", fn ($join) => $join->on('c.id', '=', "l.{$key}")->on('c.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)
            ->where('l.property_id', $propertyId)
            ->whereNull('c.archived_at')
            ->lockForUpdate()
            ->get(["l.{$key} as contract_id", 'l.valid_from', 'l.valid_to', 'c.terminated_on', 'c.number'])
            ->map(function ($row) {
                $ends = array_filter([$row->valid_to, $row->terminated_on]);

                return ['contract' => (int) $row->contract_id, 'number' => (int) $row->number,
                    'from' => $row->valid_from, 'to' => $ends === [] ? null : min($ends)];
            })
            ->filter(fn ($p) => $p['to'] === null || $p['to'] > $p['from'])
            ->values();

        foreach ($periods as $i => $a) {
            foreach ($periods->slice($i + 1) as $b) {
                $overlap = ($b['to'] === null || $a['from'] < $b['to']) && ($a['to'] === null || $b['from'] < $a['to']);
                if (! $overlap) {
                    continue;
                }

                if ($a['contract'] === $b['contract']) {
                    $this->reject('lines', 'property_twice', ['property' => $property->number]);
                }

                $other = $a['contract'] === $current->id ? $b : $a;
                $this->reject('lines', $this->busyMessage(), ['property' => $property->number, 'contract' => $other['number']]);
            }
        }
    }

    // Проверки на входа

    protected function companyId(array $data): int
    {
        $companyId = CompanyScope::restrictedCompanyId() ?? ($data['company_id'] ?? null);
        if ($companyId === null) {
            throw new LogicException('Без влязъл потребител трябва да се подаде company_id.');
        }

        return (int) $companyId;
    }

    protected function propertyId(int $companyId, Property|int|string|null $property): int
    {
        $id = $property instanceof Property ? $property->id : $property;

        $exists = $id !== null && Property::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->whereNull('archived_at')->whereKey($id)->exists();

        return $exists ? (int) $id : $this->reject('lines', 'property_not_found');
    }

    /**
     * Сума в евро с до два знака (запетая или точка). Над 0, или 0 и повече
     * при $allowZero. Без добавката bcmath (може да я няма на хостинга):
     * до 12 цифри float е точен.
     */
    protected function money($value, string $field, string $message, bool $allowZero = false): string
    {
        if ($value === null || $value === '' || ! preg_match('/^\d{1,10}([.,]\d{1,2})?$/', (string) $value)) {
            $this->reject($field, $message);
        }

        $number = (float) str_replace(',', '.', (string) $value);

        return $number > 0 || ($allowZero && $number == 0)
            ? number_format($number, 2, '.', '')
            : $this->reject($field, $message);
    }

    protected function date($value, string $field, string $message): CarbonImmutable
    {
        if ($value === null || $value === '') {
            $this->reject($field, $message);
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            $this->reject($field, $message);
        }
    }

    /** Началната дата на всеки ред; празна = датата на договора. */
    protected function lineStart(array $line, CarbonImmutable $signedOn): CarbonImmutable
    {
        return isset($line['start_on']) && $line['start_on'] !== ''
            ? $this->date($line['start_on'], 'lines', 'signed_on_required')
            : $signedOn;
    }

    // Варианти

    protected function contractOf(Model $annex): Model
    {
        $class = $this->contractClass();

        return $class::query()->withoutGlobalScopes()
            ->where('company_id', $annex->company_id)
            ->lockForUpdate()
            ->findOrFail($annex->{$this->contractKey()});
    }

    protected function openLine(Model $contract, Property|int $property): Model
    {
        $id = $property instanceof Property ? $property->id : $property;

        $line = $contract->lines()->where('property_id', $id)->whereNull('valid_to')->lockForUpdate()->first();

        if ($line === null) {
            $number = DB::table('properties')->where('company_id', $contract->company_id)->where('id', $id)->value('number');
            $this->reject('lines', 'property_not_in_contract', ['property' => $number]);
        }

        return $line;
    }

    /** Дата на промяна по анекс: след началото на сегашния вариант и преди прекратяването. */
    protected function changeDate(Model $contract, Model $current, $from): CarbonImmutable
    {
        $from = $this->date($from, 'valid_from', 'change_too_early');

        if ($from->lte($current->valid_from)) {
            $this->reject('valid_from', 'change_too_early');
        }
        $this->ensureBeforeTermination($contract, $from);

        return $from;
    }

    /** Дата на нов имот по анекс: не преди началото на договора и преди прекратяването. */
    protected function addDate(Model $contract, $from): CarbonImmutable
    {
        $from = $this->date($from, 'valid_from', 'change_too_early');

        $start = $contract->startsOn();
        if ($start !== null && $from->lt($start)) {
            $this->reject('valid_from', 'change_too_early');
        }
        $this->ensureBeforeTermination($contract, $from);

        return $from;
    }

    protected function ensureBeforeTermination(Model $contract, CarbonImmutable $from): void
    {
        if ($contract->terminated_on !== null && $from->gte($contract->terminated_on)) {
            $this->reject('valid_from', 'change_after_termination');
        }
    }

    /** Затваря сегашния вариант и добавя нов от датата, по анекса. */
    protected function replaceVersion(Model $contract, Model $annex, Model $current, $from, array $values): Model
    {
        $from = $this->changeDate($contract, $current, $from);

        $current->forceFill(['valid_to' => $from])->save();

        return $this->newVersion($current->newInstance(), $contract, $from, $annex, $values);
    }

    protected function newVersion(Model $version, Model $contract, CarbonImmutable $from, ?Model $annex, array $values): Model
    {
        $version->forceFill(array_merge([
            'company_id' => $contract->company_id,
            $this->contractKey() => $contract->id,
            'valid_from' => $from,
            'valid_to' => null,
            'annex_id' => $annex?->id,
        ], $this->versionAttributes($contract), $values))->save();

        return $version;
    }

    protected function reject(string $field, string $message, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('contracts.messages.'.$message, $replace)]);
    }
}
