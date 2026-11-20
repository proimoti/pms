<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
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
 *
 * Всички грешки наведнъж (PROJECT.md, „Дати в интерфейса“, 20.11.2026): записът
 * от формата (нов договор, анекс, поправка) върви в collecting(). Всяка част
 * се проверява отделно в attempt(): при грешка тя се връща назад, грешката се
 * пази и проверката продължава. Накрая, ако има грешки, не се записва нищо и
 * се показват всички. Отделните операции (например changeRent) извън формата
 * спират на първата грешка, както досега.
 */
abstract class ContractService
{
    /** Събраните грешки при записа от формата; null = първата грешка спира. */
    private ?MessageBag $collected = null;

    /** Колко части са с грешка (и такива без собствено съобщение). */
    private int $failures = 0;

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
            $before = $this->failures;

            $this->attempt(function () use ($class, $contract, $number) {
                $taken = $class::query()->withoutGlobalScopes()
                    ->where('company_id', $contract->company_id)
                    ->where($this->contractKey(), $contract->id)
                    ->where('number', $number)
                    ->exists();

                if ($number < 1 || $taken) {
                    $this->reject('number', 'annex_number_taken', ['number' => $number]);
                }
            });
            $signedOn = $this->attempt(fn () => $this->date($signedOn, 'signed_on', 'signed_on_required'));

            if ($this->failures > $before) {
                throw new SkippedCheck;
            }

            $annex = new $class;
            $annex->forceFill([
                'company_id' => $contract->company_id,
                $this->contractKey() => $contract->id,
                'number' => $number,
                'signed_on' => $signedOn,
                'note' => $note,
            ])->save();

            return $annex;
        });
    }

    /**
     * Анексът от формата. Ако самият анекс е грешен (зает номер, грешна
     * дата), промените в него пак се проверяват с временен анекс; накрая
     * всичко се връща назад, защото има грешка.
     */
    protected function annexForChanges(Model $contract, array $data): Model
    {
        $number = $data['number'] ?? null;

        $annex = $this->attempt(fn () => $this->addAnnex($contract,
            $number === null || $number === '' ? $this->nextAnnexNumber($contract) : (int) $number,
            $data['signed_on'] ?? null, $data['note'] ?? null));

        if ($annex !== null) {
            return $annex;
        }

        $class = $this->annexClass();
        $standIn = new $class;
        $standIn->forceFill([
            'company_id' => $contract->company_id,
            $this->contractKey() => $contract->id,
            'number' => $this->nextAnnexNumber($contract),
            'signed_on' => CarbonImmutable::today(),
        ])->save();

        return $standIn;
    }

    /** Общата дата на анекса: ако липсва, а трябва, редовете без своя дата не се проверяват. */
    protected function annexFrom(array $lines, $from, bool $alsoNeeded = false)
    {
        $needsFrom = $alsoNeeded
            || collect($lines)->contains(fn ($line) => ($line['from'] ?? null) === null || $line['from'] === '');

        if ($needsFrom && ($from === null || $from === '')) {
            $this->attempt(fn () => $this->reject('from', 'annex_from_required'));

            return InvalidInput::instance();
        }

        return $from;
    }

    /**
     * Удължава срока (дата край) по анекс (PROJECT.md, т. 2 „Удължаване с анекс“).
     * Новият срок е по-късен от сегашния; старият остава в историята. Договор
     * без срок или прекратен не се удължава. Срокът не влияе на сумите.
     */
    public function extendTerm(Model $annex, $endOn): Model
    {
        return DB::transaction(function () use ($annex, $endOn) {
            $contract = $this->contractOf($annex);
            $endOn = $this->date($endOn, 'end_on', 'extension_date_required');

            if ($contract->end_on === null) {
                $this->reject('end_on', 'extension_without_term');
            }
            if ($contract->terminated_on !== null) {
                $this->reject('end_on', 'extension_after_termination');
            }
            if ($contract->extensions()->where('annex_id', $annex->id)->exists()) {
                $this->reject('end_on', 'extension_annex_used');
            }
            if ($endOn->lte($contract->end_on)) {
                $this->reject('end_on', 'extension_not_later', ['date' => $contract->end_on->format('d.m.Y')]);
            }

            $extension = $contract->extensions()->make();
            $extension->forceFill([
                'company_id' => $contract->company_id,
                'annex_id' => $annex->id,
                'previous_end_on' => $contract->end_on,
                'end_on' => $endOn,
            ])->save();

            $contract->forceFill(['end_on' => $endOn])->save();

            return $extension;
        });
    }

    /**
     * Поправка на ред на място (PROJECT.md, „Поправки“): имот, начало (заедно
     * с края на предишния вариант на същия имот, ако е бил долепен), край само
     * при имот, махнат с анекс, и сумите на реда (correctLineValues).
     */
    protected function correctLine(Model $contract, int $id, array $values): void
    {
        DB::transaction(function () use ($contract, $id, $values) {
            $line = $contract->lines()->whereKey($id)->lockForUpdate()->first()
                ?? $this->reject('lines', 'row_not_in_contract');
            $before = $this->failures;

            if (array_key_exists('property_id', $values)) {
                $this->attempt(function () use ($contract, $line, $values) {
                    $line->property_id = $this->propertyId($contract->company_id, $values['property_id']);
                });
            }

            $this->attempt(fn () => $this->correctLineValues($contract, $line, $values));

            if (array_key_exists('valid_from', $values)) {
                $this->attempt(function () use ($contract, $line, $values) {
                    $from = $this->date($values['valid_from'], 'lines', 'period_invalid');

                    if (! $from->equalTo($line->valid_from)) {
                        $previous = $contract->lines()
                            ->where('property_id', $line->getOriginal('property_id'))
                            ->where('valid_to', $line->valid_from->toDateString())
                            ->lockForUpdate()->first();

                        if ($previous !== null) {
                            if ($from->lte($previous->valid_from)) {
                                $this->reject('lines', 'change_too_early');
                            }
                            $previous->forceFill(['valid_to' => $from])->save();
                        }

                        $line->valid_from = $from;
                    }
                });
            }

            if (array_key_exists('valid_to', $values)) {
                $this->attempt(function () use ($line, $values) {
                    if ($line->closed_by_annex_id === null) {
                        $this->reject('lines', 'end_not_editable');
                    }
                    $line->valid_to = $this->date($values['valid_to'], 'lines', 'period_invalid');
                });
            }

            // Ред с грешка не се записва (и промяната на предишния му вариант се връща).
            if ($this->failures > $before) {
                throw new SkippedCheck;
            }

            if ($line->valid_to !== null && $line->valid_to->lte($line->valid_from)) {
                $this->reject('lines', 'period_invalid');
            }

            $line->save();
        });
    }

    /** Сумите на реда при поправка (договорът за управление: сума; за наем: наем и депозит). */
    protected function correctLineValues(Model $contract, Model $line, array $values): void {}

    /** Следващият № на анекс в договора (най-големият + 1). */
    public function nextAnnexNumber(Model $contract): int
    {
        $class = $this->annexClass();

        return (int) $class::query()->withoutGlobalScopes()
            ->where('company_id', $contract->company_id)
            ->where($this->contractKey(), $contract->id)
            ->max('number') + 1;
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
            $this->attempt(fn () => $this->ensurePropertyIsFree($contract->company_id, (int) $id, $contract));
        }
    }

    /**
     * Проверка преди записа на нов договор: имотът свободен ли е от датата
     * нататък (новият ред е без край) и не е ли вече в същата форма. Така
     * застъпването се вижда заедно с другите грешки. Записът после пак минава
     * през ensurePropertyIsFree (със заключване).
     *
     * @param  list<int>  $earlier  имотите от предишните редове на формата
     */
    protected function ensureFreeFrom(Model $contract, int $companyId, int $propertyId, CarbonImmutable $from, array $earlier): void
    {
        $number = fn () => DB::table('properties')->where('company_id', $companyId)->where('id', $propertyId)->value('number');

        if (in_array($propertyId, $earlier, true)) {
            $this->reject('lines', 'property_twice', ['property' => $number()]);
        }

        $busy = $this->periodsOf($contract, $companyId, $propertyId, lock: false)
            ->first(fn ($p) => $p['to'] === null || $p['to'] > $from->toDateString());

        if ($busy !== null) {
            $this->reject('lines', $this->busyMessage(), ['property' => $number(), 'contract' => $busy['number']]);
        }
    }

    /** Периодите на имота в неархивираните договори от този вид (без празните). */
    private function periodsOf(Model $contract, int $companyId, int $propertyId, bool $lock = true): Collection
    {
        $contracts = $contract->getTable();
        $lines = $contract->lines()->getRelated()->getTable();
        $key = $this->contractKey();

        return DB::table("{$lines} as l")
            ->join("{$contracts} as c", fn ($join) => $join->on('c.id', '=', "l.{$key}")->on('c.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)
            ->where('l.property_id', $propertyId)
            ->whereNull('c.archived_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(["l.{$key} as contract_id", 'l.valid_from', 'l.valid_to', 'c.terminated_on', 'c.number'])
            ->map(function ($row) {
                $ends = array_filter([$row->valid_to, $row->terminated_on]);

                return ['contract' => (int) $row->contract_id, 'number' => (int) $row->number,
                    'from' => substr($row->valid_from, 0, 10), 'to' => $ends === [] ? null : substr(min($ends), 0, 10)];
            })
            ->filter(fn ($p) => $p['to'] === null || $p['to'] > $p['from'])
            ->values();
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

        $periods = $this->periodsOf($current, $companyId, $propertyId);

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

    /**
     * Имот на фирмата, неархивиран и видим за потребителя: служител с обхват
     * „само прикачените“ слага в договор само прикачени към него имоти.
     */
    protected function propertyId(int $companyId, Property|int|string|null $property): int
    {
        $id = $property instanceof Property ? $property->id : $property;

        $exists = $id !== null && Property::query()
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
        if ($value instanceof InvalidInput) {
            throw new SkippedCheck; // формата вече казва, че датата е грешна
        }

        if ($value === null || $value === '') {
            $this->reject($field, $message);
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            $this->reject($field, $message);
        }
    }

    /**
     * Началната дата на всеки ред; празна = датата на договора. Ако датата на
     * договора е грешна (null), ред без своя дата не може да се провери.
     */
    protected function lineStart(array $line, ?CarbonImmutable $signedOn): CarbonImmutable
    {
        if (isset($line['start_on']) && $line['start_on'] !== '') {
            return $this->date($line['start_on'], 'lines', 'signed_on_required');
        }

        return $signedOn ?? throw new SkippedCheck;
    }

    /** Свободен ли е ръчно даденият номер на нов договор (празен = автоматичен). */
    protected function ensureContractNumberIsFree(Model $contract, $number): void
    {
        if ($number === null || $number === '') {
            return;
        }

        $contract->number = (int) $number;
        $contract->ensureNumberIsFree();
    }

    // Всички грешки наведнъж

    /**
     * Записът от формата: всички части се проверяват, грешките се събират и,
     * ако има поне една, не се записва нищо (всичко е в една транзакция).
     * Знак InvalidInput в данните (грешно поле във формата) също отказва записа.
     */
    protected function collecting(array $data, Closure $body): mixed
    {
        if ($this->collected !== null) {
            return $body();
        }

        $this->collected = new MessageBag;
        $this->failures = InvalidInput::isIn($data) ? 1 : 0;

        try {
            return DB::transaction(function () use ($body) {
                $result = null;

                try {
                    $result = $body();
                } catch (ValidationException $e) {
                    $this->keep($e);
                } catch (SkippedCheck) {
                    $this->failures++;
                }

                if ($this->failures > 0) {
                    throw ValidationException::withMessages($this->collected->toArray());
                }

                return $result;
            });
        } finally {
            $this->collected = null;
            $this->failures = 0;
        }
    }

    /**
     * Една отделна част от записа. При събиране на грешките: ако е грешна,
     * се връща назад (в базата), грешката се пази и се връща $fallback. Без
     * събиране грешката спира всичко, както досега.
     */
    protected function attempt(Closure $part, mixed $fallback = null): mixed
    {
        if ($this->collected === null) {
            return $part();
        }

        try {
            return DB::transaction(fn () => $part());
        } catch (ValidationException $e) {
            $this->keep($e);
        } catch (SkippedCheck) {
            $this->failures++;
        }

        return $fallback;
    }

    /** Колко части досега са с грешка (за „има ли нова грешка в този ред“). */
    protected function failures(): int
    {
        return $this->failures;
    }

    private function keep(ValidationException $e): void
    {
        $this->failures++;

        foreach ($e->errors() as $field => $messages) {
            foreach ((array) $messages as $message) {
                $this->collected->add($field, $message); // еднакво съобщение се показва веднъж
            }
        }
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
