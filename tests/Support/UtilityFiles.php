<?php

namespace Tests\Support;

use App\Support\Xlsx\XlsxWriter;
use DateTimeImmutable;

/**
 * Измислени файлове за внос с колоните на EasyPay и ePay (PROJECT.md, т. 5.3,
 * „Внос на платените сметки“): в заглавията на EasyPay има неразделими
 * интервали, абонатният номер е ЧИСЛО (без нулите отпред), датата е дата и
 * час на Excel; в ePay номерът е в скоби в „Описание“, „Системен код“ е текст
 * от 16 цифри, „Подробно описание“ е многоредов текст.
 */
trait UtilityFiles
{
    private const NBSP = "\u{00A0}";

    /**
     * Файл на EasyPay.
     *
     * @param  list<array{ref:int, date:string, number:int|string, provider:string, amount:float|string}>  $rows
     */
    protected function easypayFile(array $rows): string
    {
        $n = self::NBSP;
        $sheet = [["{$n}Номер на превода{$n}", "{$n}Дата{$n}", "{$n}Кл.рег.{$n}", "{$n}Абонатен номер{$n}", "{$n}Търговец{$n}", "{$n}Сума{$n}"]];

        foreach ($rows as $row) {
            $sheet[] = [$row['ref'], new DateTimeImmutable($row['date']), 4401, $row['number'], $row['provider'], $row['amount']];
        }

        return $this->writeXlsx($sheet);
    }

    /**
     * Файл на ePay (като банково извлечение).
     *
     * @param  list<array{date:string, in?:float|string|null, out?:float|string|null, provider:string, code:string, description:string, details?:string}>  $rows
     */
    protected function epayFile(array $rows): string
    {
        $sheet = [
            ['Извлечение по сметка (измислено)'],
            ['Дата и час', 'Сметка', 'Приход', 'Разход', 'Кореспондент', 'Системен код', 'Описание', 'Подробно описание'],
        ];

        foreach ($rows as $row) {
            $sheet[] = [$row['date'], 'EPAY-0001', $row['in'] ?? null, $row['out'] ?? null, $row['provider'], $row['code'], $row['description'], $row['details'] ?? ''];
        }

        return $this->writeXlsx($sheet);
    }

    protected function writeXlsx(array $sheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pms-test-').'.xlsx';
        (new XlsxWriter)->write($path, $sheet);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
