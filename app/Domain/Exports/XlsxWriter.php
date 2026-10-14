<?php

namespace App\Domain\Exports;

/**
 * E-49: a small, dependency-free .xlsx writer (Office Open XML in a stored ZIP), so no
 * extra PHP extension is needed. Title and filters on top, bold frozen header row,
 * number formats per column, sensible widths, and a totals row with SUM formulas
 * (Excel recalculates them on open).
 */
final class XlsxWriter
{
    // Style ids (cellXfs order in styles()).
    private const S_TEXT = 0;

    private const S_HEADER = 1;

    private const S_INT = 2;

    private const S_DEC = 3;

    private const S_PCT = 4;

    private const S_TITLE = 5;

    private const S_MUTED = 6;

    private const S_TOTAL_LABEL = 7;

    private const S_TOTAL_INT = 8;

    private const S_TOTAL_DEC = 9;

    public function make(ExportTable $t): string
    {
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->x(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $t->title) ?? 'Report', 0, 31)).'" sheetId="1" r:id="rId1"/></sheets><calcPr fullCalcOnLoad="1"/></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => $this->styles(),
            'xl/worksheets/sheet1.xml' => $this->sheet($t),
        ];

        return $this->zip($files);
    }

    private function sheet(ExportTable $t): string
    {
        $cols = $t->columns;
        $rows = [];
        $r = 1;
        $rows[] = $this->row($r++, [$this->str('A', $t->title, self::S_TITLE)]);
        $filters = implode(' · ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($t->filters), $t->filters));
        $rows[] = $this->row($r++, [$this->str('A', $filters !== '' ? $filters : 'All data', self::S_MUTED)]);
        $r++;
        $headerRow = $r;
        $rows[] = $this->row($r++, array_map(fn ($c, $i) => $this->str($this->col($i), $c['label'].(($c['type'] === 'money' && ($c['currency'] ?? null)) ? ' ('.$c['currency'].')' : ''), self::S_HEADER), $cols, array_keys($cols)));
        $first = $r;
        foreach ($t->rows as $data) {
            $cells = [];
            foreach ($cols as $i => $c) {
                $cells[] = $this->cell($this->col($i).$r, $data[$c['key']] ?? null, $c['type']);
            }
            $rows[] = $this->row($r++, $cells);
        }
        $last = $r - 1;
        $hasTotals = $t->rows !== [] && array_filter($cols, fn ($c) => $t->sums($c)) !== [];
        if ($hasTotals) {
            $cells = [];
            foreach ($cols as $i => $c) {
                $ref = $this->col($i).$r;
                if ($i === 0) {
                    $cells[] = $this->str($this->col(0), 'Total', self::S_TOTAL_LABEL, $r);
                } elseif ($t->sums($c)) {
                    $style = $c['type'] === 'number' ? self::S_TOTAL_INT : self::S_TOTAL_DEC;
                    $cells[] = '<c r="'.$ref.'" s="'.$style.'"><f>SUM('.$this->col($i).$first.':'.$this->col($i).$last.')</f></c>';
                }
            }
            $rows[] = $this->row($r, $cells);
        }

        $widths = '';
        foreach ($cols as $i => $c) {
            $len = mb_strlen($c['label']) + 2;
            foreach (array_slice($t->rows, 0, 200) as $data) {
                $len = max($len, mb_strlen((string) ($data[$c['key']] ?? '')) + 2);
            }
            $w = min(60, max($c['type'] === 'text' ? 14 : 12, $len));
            $widths .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$widths.'</cols><sheetData>'.implode('', $rows).'</sheetData>'
            .'</worksheet>';
    }

    private function cell(string $ref, mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($type === 'text' || $type === 'date' || ! is_numeric($value)) {
            // Inline strings are always text in Excel, never formulas, so no prefix is needed.
            $text = is_scalar($value) ? (string) $value : '';

            return '<c r="'.$ref.'" t="inlineStr" s="'.self::S_TEXT.'"><is><t xml:space="preserve">'.$this->x($text).'</t></is></c>';
        }
        $n = $type === 'money' ? ((int) $value) / 100 : (is_int($value) ? $value : (float) $value);
        $style = match ($type) {
            'number' => self::S_INT,
            'percent' => self::S_PCT,
            default => self::S_DEC,
        };

        return '<c r="'.$ref.'" s="'.$style.'"><v>'.$n.'</v></c>';
    }

    private function str(string $col, string $text, int $style, ?int $row = null): string
    {
        $ref = $row !== null ? $col.$row : $col.'%ROW%';

        return '<c r="'.$ref.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.$this->x($text).'</t></is></c>';
    }

    /**
     * @param  list<string>  $cells
     */
    private function row(int $r, array $cells): string
    {
        return '<row r="'.$r.'">'.str_replace('%ROW%', (string) $r, implode('', $cells)).'</row>';
    }

    private function col(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    private function x(string $s): string
    {
        // Strip characters XML 1.0 forbids, then escape.
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '', ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0&quot;%&quot;"/></numFmts>'
            .'<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FF9CA3AF"/></bottom><diagonal/></border><border><left/><right/><top style="thin"><color rgb="FF111827"/></top><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="10">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1"/>'
            .'<xf numFmtId="3" fontId="1" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1" applyNumberFormat="1"/>'
            .'<xf numFmtId="4" fontId="1" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1" applyNumberFormat="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /**
     * A ZIP with stored (uncompressed) entries: no zip extension needed, and Excel opens it.
     *
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $data = '';
        $central = '';
        $offset = 0;
        [$time, $date] = $this->dosTime();
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $header = pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0).$name;
            $data .= $header.$content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($header) + $size;
        }

        return $data.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dosTime(): array
    {
        $n = now();

        return [($n->hour << 11) | ($n->minute << 5) | intdiv($n->second, 2), (($n->year - 1980) << 9) | ($n->month << 5) | $n->day];
    }
}
