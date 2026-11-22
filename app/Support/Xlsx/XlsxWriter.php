<?php

namespace App\Support\Xlsx;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Прост файл xlsx с един лист (за тестовете и демо файловете за внос).
 * Без външен пакет: xlsx е zip архив с няколко XML файла. Текстът отива в
 * общия списък с текстове (както го прави Excel), числата като числа, датите
 * като числа на Excel с формат „дата и час“. Празна клетка (null) се пропуска.
 */
final class XlsxWriter
{
    /** @param list<list<string|int|float|DateTimeInterface|null>> $rows */
    public function write(string $path, array $rows): void
    {
        $strings = [];
        $sheet = '';

        foreach ($rows as $r => $row) {
            $line = $r + 1;
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                if ($value === null) {
                    continue;
                }
                $ref = self::column($c).$line;

                if ($value instanceof DateTimeInterface) {
                    $cells .= '<c r="'.$ref.'" s="1"><v>'.self::serial($value).'</v></c>';
                } elseif (is_int($value) || is_float($value)) {
                    $cells .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $index = $strings[$value] ??= count($strings);
                    $cells .= '<c r="'.$ref.'" t="s"><v>'.$index.'</v></c>';
                }
            }
            $sheet .= '<row r="'.$line.'">'.$cells.'</row>';
        }

        $shared = '';
        foreach (array_keys($strings) as $text) {
            $shared .= '<si><t xml:space="preserve">'.htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Файлът не може да се създаде.');
        }

        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

        $zip->addFromString('[Content_Types].xml', $head.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $head.'<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'">'
            .'<sheets><sheet name="Лист1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.$rel.'/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rId3" Type="'.$rel.'/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $head.'<styleSheet xmlns="'.$main.'">'
            .'<fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf/><xf numFmtId="22" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/sharedStrings.xml', $head.'<sst xmlns="'.$main.'" count="'.count($strings).'" uniqueCount="'.count($strings).'">'.$shared.'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $head.'<worksheet xmlns="'.$main.'"><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close();
    }

    /** A, B, ..., Z, AA, ... */
    private static function column(int $index): string
    {
        $name = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26).$name;
        }

        return $name;
    }

    /** Дата и час като число на Excel (дни от 30.12.1899). */
    private static function serial(DateTimeInterface $date): string
    {
        $days = (int) floor(((int) $date->format('U') + (int) $date->format('Z')) / 86400) + 25569;
        $seconds = (int) $date->format('H') * 3600 + (int) $date->format('i') * 60 + (int) $date->format('s');

        return rtrim(rtrim(sprintf('%.10F', $days + $seconds / 86400), '0'), '.');
    }
}
