<?php

namespace App\Services\Export;

use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * Файлът за „Операции → Импорт на операции → Импорт от Excel“ в Микроинвест
 * Делта Pro (PROJECT.md, т. 5.4 и „Експорт към Микроинвест“, 26.11.2026), по
 * шаблона на възложителя:
 *
 * - лист „Операции“: заглавен ред и данни от ред 2, колони A–O (P няма);
 *   A — число с формат „1 234,56 €“; B — „дебит-кредит“; C, I, O — първо число на
 *   месеца като истинска дата (дд.мм.гггг); D, E, F, K, L — празни; G — документ;
 *   H — „МО“; J — основание; M и N — текст „1“;
 * - лист „Настройка Операции“: точно като в шаблона, с B3 = 2 и B4 = последният
 *   ред с данни (попълва се сам), включително реда „IBAN → P“.
 *
 * Без външен пакет (xlsx е zip с XML). Никога не се пише в базата на Микроинвест.
 */
final class MicroinvestWorkbook
{
    public const HEADER = ['Сума', 'Сметки', 'За месец', 'Партньор', 'Булстат/ЕИК', 'ДДС номер', 'Документ', 'Тип', 'Дата на документа',
        'Основание', 'Забележка', 'Втора забележка', 'Вид сделка', 'VIES', 'Месец за експорт'];

    /** Редовете на „Настройка Операции“ от ред 5 нататък, както в шаблона. */
    private const MAPPING = [
        ['Колона за суми', 'A'], ['Колона за сметки', 'B'], ['Колона за дата', 'C'], ['Колона на партньор', 'D'],
        ['Колона за Булстат\\ЕИК', 'E'], ['Колона за ДДС номер', 'F'], ['Колона за номер на док.', 'G'], ['IBAN', 'P'],
        ['Колона за вид док.', 'H'], ['Колона за дата на док.', 'I'], ['Колона за основание', 'J'], ['Колона за забележка', 'K'],
        ['Колона за втора забележка', 'L'], ['Колона за вид сделка', 'M'], ['Колона за VIES', 'N'], ['Колона за месец за експорт', 'O'],
    ];

    private const DESCRIPTION = 'Настоящият файл служи като пример за импорт на операции в счетоводната програма на Microinvest. Във файла са въведени '
        .'всички основни полета от операцията. За да извършите импорт на данните е необходимо да извършите следното: Стартирайте Microinvest Делта Pro. '
        .'От главното меню изберете Операции->Импорт на операции-> Импорт от Excel. За да работи примера е необходимо предварително да имате дефинирани '
        .'следните сметки: 302/1; 302/2 (с категория Материали); 401/1, 411, 453, 453/1, 453/2, 501, 503, 504/1, 601, 602, 703 и  и партньори: '
        .'Корона ЕТ, Агенция МД, АД Хемустурист и Лилия ЕООД';

    /** Стилове: 0 обикновен, 1 дата (формат 14 — дд.мм.гггг), 2 сума в евро (формат 164). */
    private const DATE = 1;

    private const MONEY = 2;

    /** @var array<string, int> */
    private array $strings = [];

    /** @param list<array{cents: int, debit: string, credit: string, basis: string, document: string}> $rows */
    public function write(string $path, CarbonImmutable $month, array $rows): void
    {
        $this->strings = [];
        $date = (string) $this->serial($month->startOfMonth());

        $sheet = $this->row(1, array_map(fn ($title) => ['s', $title], self::HEADER));
        foreach (array_values($rows) as $i => $row) {
            $sheet .= $this->row($i + 2, [
                ['money', number_format($row['cents'] / 100, 2, '.', '')],
                ['s', $row['debit'].'-'.$row['credit']],
                ['date', $date], ['s', ''], ['s', ''], ['s', ''],
                ['s', $row['document']], ['s', 'МО'], ['date', $date], ['s', $row['basis']],
                ['s', ''], ['s', ''], ['s', '1'], ['s', '1'], ['date', $date],
            ]);
        }

        $settings = $this->row(1, [['s', 'Настройка'], null, ['s', 'Описание']])
            .$this->row(2, [['s', 'Sheet с данни'], ['s', 'Операции'], ['s', self::DESCRIPTION]])
            .$this->row(3, [['s', 'Начален ред'], ['n', '2']])
            .$this->row(4, [['s', 'Краен ред'], ['n', (string) (count($rows) + 1)]]);
        foreach (self::MAPPING as $i => [$label, $column]) {
            $settings .= $this->row($i + 5, [['s', $label], ['s', $column]]);
        }

        $this->zip($path, $sheet, $settings);
    }

    /** @param list<?array{0: string, 1: string}> $cells */
    private function row(int $line, array $cells): string
    {
        $xml = '';
        foreach ($cells as $c => $cell) {
            if ($cell === null) {
                continue;
            }
            [$type, $value] = $cell;
            $ref = chr(65 + $c).$line;
            $xml .= match ($type) {
                'money' => '<c r="'.$ref.'" s="'.self::MONEY.'"><v>'.$value.'</v></c>',
                'date' => '<c r="'.$ref.'" s="'.self::DATE.'"><v>'.$value.'</v></c>',
                'n' => '<c r="'.$ref.'"><v>'.$value.'</v></c>',
                default => '<c r="'.$ref.'" t="s"><v>'.($this->strings[$value] ??= count($this->strings)).'</v></c>',
            };
        }

        return '<row r="'.$line.'">'.$xml.'</row>';
    }

    private function serial(CarbonImmutable $date): int
    {
        return (int) CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->diffInDays(CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, 'UTC'));
    }

    private function zip(string $path, string $operations, string $settings): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Файлът не може да се създаде.');
        }
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $shared = '';
        foreach (array_keys($this->strings) as $text) {
            $shared .= '<si><t xml:space="preserve">'.htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
        }

        $zip->addFromString('[Content_Types].xml', $head.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $head.'<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheets>'
            .'<sheet name="Операции" sheetId="1" r:id="rId1"/><sheet name="Настройка Операции" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.$rel.'/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="'.$rel.'/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rId4" Type="'.$rel.'/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $head.'<styleSheet xmlns="'.$main.'">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00\ \€;\-#,##0.00\ \€"/></numFmts>'
            .'<fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="3"><xf/><xf numFmtId="14" applyNumberFormat="1"/><xf numFmtId="164" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/sharedStrings.xml', $head.'<sst xmlns="'.$main.'" count="'.count($this->strings).'" uniqueCount="'.count($this->strings).'">'.$shared.'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $head.'<worksheet xmlns="'.$main.'"><sheetData>'.$operations.'</sheetData></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', $head.'<worksheet xmlns="'.$main.'"><sheetData>'.$settings.'</sheetData></worksheet>');
        $zip->close();
    }
}
