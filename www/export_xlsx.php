<?php
declare(strict_types=1);

function exportCellXml(mixed $value, int $column, int $row, bool $header = false): string {
    $letter = '';
    for ($n = $column + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $letter = chr(65 + ($n - 1) % 26) . $letter;
    }
    $ref = $letter . $row;
    $style = $header ? ' s="1"' : '';
    if (!$header && (is_int($value) || is_float($value)) && is_finite((float)$value)) {
        return '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
    }
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$value) ?? '';
    $escaped = htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
}

function exportSheetXml(array $rows, array $widths): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($widths as $i => $width) {
        $col = $i + 1;
        $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';
    foreach ($rows as $i => $cells) {
        $row = $i + 1;
        $xml .= '<row r="' . $row . '">';
        foreach ($cells as $column => $value) {
            $xml .= exportCellXml($value, $column, $row, $i === 0);
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    if (count($rows) > 1) {
        $last = '';
        for ($n = count($rows[0]); $n > 0; $n = intdiv($n - 1, 26)) {
            $last = chr(65 + ($n - 1) % 26) . $last;
        }
        $xml .= '<autoFilter ref="A1:' . $last . count($rows) . '"/>';
    }
    return $xml . '</worksheet>';
}

function buildExportXlsx(array $data, DateTimeImmutable $from, DateTimeImmutable $to, string $path): void {
    $summary = [['Data', 'Zjedzone kcal', 'Spalone kcal', 'Bilans kcal', 'Płyny ml', 'Kroki', 'Liczba wpisów jedzenia i napojów', 'Liczba aktywności']];
    $foodRows = [['Data', 'Godzina', 'Kategoria', 'Produkt lub danie', 'Ilość', 'Jednostka', 'Kalorie kcal', 'Szczegóły']];
    $activityRows = [['Data', 'Godzina', 'Aktywność', 'Kroki', 'Spalone kcal']];
    $days = is_array($data['days'] ?? null) ? $data['days'] : [];
    for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
        $key = $date->format('Y-m-d');
        $day = is_array($days[$key] ?? null) ? $days[$key] : [];
        $meals = is_array($day['meals'] ?? null) ? $day['meals'] : [];
        $activities = is_array($day['activities'] ?? null) ? $day['activities'] : [];
        $eaten = $burned = $fluids = $steps = 0;
        foreach ($meals as $meal) {
            if (!is_array($meal)) continue;
            $kcal = (float)($meal['kcal'] ?? 0);
            $eaten += $kcal;
            $category = ($meal['category'] ?? '') === 'drinks' ? 'Napoje' : (($meal['category'] ?? '') === 'other' ? 'Inne' : 'Jedzenie');
            $amount = $category === 'Napoje' ? (float)($meal['amountMl'] ?? $meal['grams'] ?? 0) : (float)($meal['grams'] ?? 0);
            if ($category === 'Napoje') $fluids += $amount;
            $foodRows[] = [$key, (string)($meal['time'] ?? ''), $category, (string)($meal['name'] ?? ''), $amount ?: '', $amount ? ($category === 'Napoje' ? 'ml' : 'g') : '', $kcal, (string)($meal['detail'] ?? '')];
        }
        foreach ($activities as $activity) {
            if (!is_array($activity)) continue;
            $activitySteps = (float)($activity['steps'] ?? 0);
            $activityKcal = (float)($activity['burnedKcal'] ?? 0);
            $steps += $activitySteps;
            $burned += $activityKcal;
            $activityRows[] = [$key, (string)($activity['createdAt'] ?? ''), (string)($activity['name'] ?? ''), $activitySteps, $activityKcal];
        }
        $summary[] = [$key, $eaten, $burned, $eaten - $burned, $fluids, $steps, count($meals), count($activities)];
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Nie można utworzyć pliku XLSX');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Podsumowanie" sheetId="1" r:id="rId1"/><sheet name="Jedzenie i napoje" sheetId="2" r:id="rId2"/><sheet name="Aktywności" sheetId="3" r:id="rId3"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF3478F6"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', exportSheetXml($summary, [15, 17, 17, 16, 15, 14, 34, 20]));
    $zip->addFromString('xl/worksheets/sheet2.xml', exportSheetXml($foodRows, [15, 12, 17, 35, 14, 14, 18, 60]));
    $zip->addFromString('xl/worksheets/sheet3.xml', exportSheetXml($activityRows, [15, 12, 35, 16, 18]));
    if (!$zip->close()) throw new RuntimeException('Nie można zapisać pliku XLSX');
}
