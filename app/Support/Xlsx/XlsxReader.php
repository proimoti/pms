<?php

namespace App\Support\Xlsx;

use Carbon\CarbonImmutable;
use SimpleXMLElement;
use ZipArchive;

/**
 * Чете първия лист на файл xlsx без външен пакет (нужно е само разширението
 * zip на PHP). Връща редовете като списъци от текстове по колони (0 = A);
 * числата са както са записани в файла (например „48.2“, „46361.43“), а
 * значението им (сума, дата, номер) го решава този, който чете.
 */
final class XlsxReader
{
    /**
     * @return list<array<int, string>>|null null, ако файлът не е xlsx
     */
    public function rows(string $path): ?array
    {
        if (! is_file($path) || filesize($path) === 0) {
            return null;
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $sheetPath = $this->firstSheetPath($zip);
            $sheetXml = $sheetPath === null ? false : $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                return null;
            }

            $strings = $this->sharedStrings($zip);
            $sheet = $this->xml($sheetXml);
            if ($sheet === null) {
                return null;
            }
        } finally {
            $zip->close();
        }

        $rows = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $cells = [];
            $next = 0;
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $index = $ref === '' ? $next : self::columnIndex($ref);
                $next = $index + 1;
                $cells[$index] = $this->value($cell, $strings);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /** Дата от число на Excel (дни от 30.12.1899, частта след точката е часът). */
    public static function dateFromSerial(float $serial): CarbonImmutable
    {
        $seconds = (int) round(($serial - 25569) * 86400);

        return CarbonImmutable::createFromTimestampUTC($seconds)->shiftTimezone(config('app.timezone'));
    }

    private function value(SimpleXMLElement $cell, array $strings): string
    {
        $type = (string) $cell['t'];

        return match ($type) {
            's' => $strings[(int) $cell->v] ?? '',
            'inlineStr' => $this->text($cell->is),
            default => (string) $cell->v,
        };
    }

    /** Текстът на <si> или <is>: обикновен <t> или няколко части <r><t>. */
    private function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }

        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        $root = $xml === false ? null : $this->xml($xml);
        if ($root === null) {
            return [];
        }

        $strings = [];
        foreach ($root->si as $item) {
            $strings[] = $this->text($item);
        }

        return $strings;
    }

    /** Пътят до първия лист според workbook.xml; иначе обичайното място. */
    private function firstSheetPath(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook !== false && $rels !== false) {
            $book = $this->xml($workbook);
            $links = $this->xml($rels);
            $sheet = $book?->sheets->sheet[0] ?? null;
            if ($sheet !== null && $links !== null) {
                $id = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                foreach ($links->Relationship as $link) {
                    if ((string) $link['Id'] === $id) {
                        $target = ltrim((string) $link['Target'], '/');

                        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                    }
                }
            }
        }

        return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
    }

    private function xml(string $content): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $root === false ? null : $root;
    }

    /** „B12“ → 1 */
    private static function columnIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', strtoupper($ref), $match);
        $index = 0;
        foreach (str_split($match[0] ?? 'A') as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
