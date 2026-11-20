<?php

namespace App\Http\Controllers\Contracts;

use App\Services\InvalidInput;
use App\Services\SkippedCheck;
use App\Support\BgDate;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Общото за формите на договорите: всички грешки наведнъж (PROJECT.md, „Дати
 * в интерфейса“, 20.11.2026). Грешна дата не спира останалите проверки: на
 * нейно място отива знак InvalidInput и проверките, които зависят от нея, се
 * прескачат. Накрая грешките от полетата и от правилата се показват заедно и
 * не се записва нищо.
 */
trait ContractForm
{
    /** Полетата с дата във формите на договорите. */
    private static array $dateFields = ['signed_on', 'end_on', 'terminated_on', 'from', 'start_on', 'valid_from', 'valid_to'];

    /**
     * Полетата от формата (само тези с правила), с датите във вида гггг-мм-дд.
     * Друга грешка освен дата (например повредено число) спира веднага.
     *
     * @return array{0: array, 1: MessageBag}
     */
    protected function formInput(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        $validator->fails();
        $errors = $validator->errors();

        foreach ($errors->keys() as $key) {
            if (! in_array(Str::afterLast($key, '.'), self::$dateFields, true)) {
                throw new ValidationException($validator);
            }
        }

        $topLevel = array_unique(array_map(fn ($key) => Str::before($key, '.'), array_keys($rules)));
        $data = Arr::only($request->all(), $topLevel);

        foreach ($errors->keys() as $key) {
            Arr::set($data, $key, InvalidInput::instance());
        }

        return [$this->isoDates($data), $errors];
    }

    /**
     * Записва; грешките от полетата и от правилата излизат заедно. Ако има
     * грешка в полетата, записът се връща назад, дори правилата да минат.
     */
    protected function submit(MessageBag $errors, Closure $save): mixed
    {
        try {
            return DB::transaction(function () use ($errors, $save) {
                $result = $save();

                if ($errors->isNotEmpty()) {
                    throw ValidationException::withMessages([]);
                }

                return $result;
            });
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($this->merged($errors, $e->errors()));
        }
    }

    /**
     * „Промени“ (информационните полета). Ако записът е отказан, всяко поле
     * се пробва и само, за да излязат всички причини; после всичко се връща.
     */
    protected function updateHeader(Model $contract, array $values): void
    {
        try {
            DB::transaction(fn () => $contract->update($values));
        } catch (ValidationException $first) {
            $messages = $first->errors();

            foreach ($values as $field => $value) {
                try {
                    DB::transaction(function () use ($contract, $field, $value) {
                        $contract->fresh()->update([$field => $value]);
                        throw new SkippedCheck; // само проба: връща се назад
                    });
                } catch (ValidationException $e) {
                    $messages = $this->merged(new MessageBag($messages), $e->errors());
                } catch (SkippedCheck) {
                }
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /** Полетата, изпратени с формата и верни („Промени“); заключеният срок не се праща. */
    protected function headerValues(Request $request, array $data): array
    {
        $values = [];

        foreach (['number', 'signed_on', 'end_on', 'terminated_on', 'note'] as $field) {
            if ($request->exists($field) && ! (($data[$field] ?? null) instanceof InvalidInput)) {
                $values[$field] = $data[$field] ?? null;
            }
        }

        return $values;
    }

    private function merged(MessageBag $errors, array $more): array
    {
        $bag = new MessageBag($errors->toArray());

        foreach ($more as $field => $messages) {
            foreach ((array) $messages as $message) {
                $bag->add($field, $message);
            }
        }

        return $bag->toArray();
    }

    /** Датите от формата (дд.мм.гггг, вече проверени) във вътрешния вид гггг-мм-дд. */
    private function isoDates(array $data): array
    {
        foreach (['signed_on', 'end_on', 'terminated_on', 'from'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = BgDate::toIso($data[$field]);
            }
        }

        foreach ((array) ($data['lines'] ?? []) as $key => $line) {
            foreach (['start_on', 'from', 'valid_from', 'valid_to'] as $field) {
                if (isset($line[$field]) && is_string($line[$field])) {
                    $data['lines'][$key][$field] = BgDate::toIso($line[$field]);
                }
            }
        }

        return $data;
    }
}
