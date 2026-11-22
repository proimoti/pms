<?php

namespace App\Services\Utilities;

use App\Models\Provider;
use App\Support\Xlsx\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Разчита файл от EasyPay или ePay (PROJECT.md, т. 5.3 „Внос на платените
 * сметки“, 21.11.2026), без предварителна обработка в Excel. Кой е файлът
 * се познава по заглавията на колоните (без значение от невидими интервали
 * и главни букви), които може да не са на първия ред.
 *
 * - EasyPay: „Номер на превода“, „Дата“, „Абонатен номер“ (число, без нулите
 *   отпред), „Търговец“, „Сума“.
 * - ePay: „Дата и час“, „Приход“, „Разход“, „Кореспондент“, „Системен код“,
 *   „Описание“ (номерът е в скоби), „Подробно описание“. Само евро.
 */
final class UtilityFileReader
{
    private const HEADER_ROWS = 20;

    private const COLUMNS = [
        'easypay' => [
            'reference' => 'номер на превода', 'date' => 'дата', 'number' => 'абонатен номер',
            'provider' => 'търговец', 'amount' => 'сума',
        ],
        'epay' => [
            'date' => 'дата и час', 'out' => 'разход', 'provider' => 'кореспондент',
            'reference' => 'системен код', 'description' => 'описание',
        ],
    ];

    private const OPTIONAL = ['epay' => ['in' => 'приход', 'details' => 'подробно описание']];

    /** @return array{0: string, 1: list<ImportRow>} източникът и редовете */
    public function read(string $path): array
    {
        $rows = (new XlsxReader)->rows($path) ?? $this->fail('not_xlsx');

        foreach (array_slice($rows, 0, self::HEADER_ROWS, true) as $headerIndex => $header) {
            $names = array_map(fn ($name) => mb_strtolower(Provider::normalizeName($name) ?? ''), $header);

            foreach (self::COLUMNS as $source => $required) {
                $columns = [];
                foreach ($required as $key => $title) {
                    $index = array_search($title, $names, true);
                    if ($index === false) {
                        continue 2;
                    }
                    $columns[$key] = $index;
                }
                foreach (self::OPTIONAL[$source] ?? [] as $key => $title) {
                    $index = array_search($title, $names, true);
                    if ($index !== false) {
                        $columns[$key] = $index;
                    }
                }

                $result = [];
                foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $cells) {
                    if (trim(implode('', $cells)) === '') {
                        continue;
                    }
                    $line = $index + 1;
                    $result[] = $source === 'easypay' ? $this->easypay($line, $cells, $columns) : $this->epay($line, $cells, $columns);
                }

                return [$source, $result];
            }
        }

        $this->fail('unknown_file');
    }

    private function easypay(int $line, array $cells, array $columns): ImportRow
    {
        $value = fn (string $key) => trim((string) ($cells[$columns[$key]] ?? ''));

        return new ImportRow(
            line: $line,
            kind: 'bill',
            reference: self::numberText($value('reference')),
            paidAt: self::date($value('date')),
            providerName: Provider::normalizeName($value('provider')),
            number: self::numberText($value('number')),
            amountCents: self::cents($value('amount')),
        );
    }

    private function epay(int $line, array $cells, array $columns): ImportRow
    {
        $value = fn (string $key) => isset($columns[$key]) ? trim((string) ($cells[$columns[$key]] ?? '')) : '';
        $description = $value('description');
        $out = $value('out');

        // Само редовете с „Разход“: приходите (например наем) се пропускат тихо.
        // Разход без номер в скоби (например „Такса банков превод“) е банкова такса.
        $number = preg_match_all('/\((\d+)\)/', $description, $matches) ? end($matches[1]) : null;
        $kind = match (true) {
            $out === '' || self::cents($out) === 0 && $value('in') !== '' => 'income',
            $number === null => 'fee',
            default => 'bill',
        };

        $details = isset($columns['details']) ? (string) ($cells[$columns['details']] ?? '') : '';

        return new ImportRow(
            line: $line,
            kind: $kind,
            reference: $value('reference') === '' ? null : $value('reference'),
            paidAt: self::date($value('date')),
            providerName: Provider::normalizeName($value('provider')),
            number: $number,
            amountCents: self::cents($out),
            description: $description === '' ? null : $description,
            details: $details === '' ? null : str_replace("\r\n", "\n", $details),
        );
    }

    /**
     * Номер, записан като число в Excel: „1000071718“, „12345.0“ или
     * „1.000071718E9“ → „1000071718“. Текст остава, както е.
     */
    private static function numberText(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d+)\.0+$/', $value, $match)) {
            return $match[1];
        }
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $value)) {
            return sprintf('%.0f', (float) $value);
        }

        return $value;
    }

    /** Сума в центове: „48.2“, „48,20“, „1 234,56“, „-12.5“; null при грешка. */
    private static function cents(string $value): ?int
    {
        $value = str_replace([' ', "\u{00A0}", '€'], '', $value);
        if (preg_match('/^-?\d+,\d+$/', $value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (int) round(((float) $value) * 100) : null;
    }

    /** Дата и час: число на Excel или текст „05.12.2026 11:20:00“, „2026-12-05 11:20“. */
    private static function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value > 1 ? XlsxReader::dateFromSerial((float) $value) : null;
        }

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($date !== null && $date->format($format) === $value) {
                    return $date;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => __('utilities.import.errors.'.$message)]);
    }
}
