<?php
/*
 * Минимальный генератор .xlsx без сторонних библиотек (нужен только ZipArchive).
 * Ячейка: число/строка или массив ['v' => значение, 'f' => формула без «=», 's' => номер стиля].
 * Для формулы 'v' — заранее посчитанное значение: Excel покажет его сразу и пересчитает при открытии.
 *
 * Лист: ['name', 'rows' => [номер строки => [номер столбца => ячейка]] (с 1), 'widths' => [столбец => ширина],
 *        'merges' => ['A1:C1'], 'freeze' => 'C10', 'filter' => 'A9:K20', 'cf' => [[sqref, xml правил]],
 *        'validations' => [xml], 'heights' => [строка => высота]]
 */

const XLSX_STYLES = [
    'default' => 0, 'title' => 1, 'head' => 2, 'group' => 3, 'label' => 4, 'param' => 5,
    'pct' => 6, 'score' => 7, 'pct_bold' => 8, 'verdict' => 9, 'hint' => 10, 'int' => 11, 'name' => 12, 'input' => 13,
];

function xlsx_col(int $n): string {
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}

function xlsx_ref(int $col, int $row): string { return xlsx_col($col) . $row; }

function xlsx_esc(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_cell_xml(string $ref, $cell): string {
    if (!is_array($cell)) $cell = ['v' => $cell];
    $s = isset($cell['s']) ? ' s="' . (int)$cell['s'] . '"' : '';
    $v = $cell['v'] ?? null;
    if (isset($cell['f'])) {
        $f = '<f>' . xlsx_esc($cell['f']) . '</f>';
        if (is_int($v) || is_float($v)) return "<c r=\"$ref\"$s>$f<v>" . xlsx_num($v) . '</v></c>';
        if (is_bool($v)) return "<c r=\"$ref\"$s t=\"b\">$f<v>" . ($v ? 1 : 0) . '</v></c>';
        return "<c r=\"$ref\"$s t=\"str\">$f<v>" . xlsx_esc((string)$v) . '</v></c>';
    }
    if ($v === null || $v === '') return $s ? "<c r=\"$ref\"$s/>" : '';
    if (is_int($v) || is_float($v)) return "<c r=\"$ref\"$s><v>" . xlsx_num($v) . '</v></c>';
    return "<c r=\"$ref\"$s t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xlsx_esc((string)$v) . '</t></is></c>';
}

function xlsx_num($v): string {
    if (is_int($v)) return (string)$v;
    $s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}

function xlsx_sheet_xml(array $sh): string {
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
       . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
    if (!empty($sh['freeze']) && preg_match('/^([A-Z]+)(\d+)$/', $sh['freeze'], $m)) {
        $colN = 0;
        foreach (str_split($m[1]) as $ch) $colN = $colN * 26 + (ord($ch) - 64);
        $xs = $colN - 1; $ys = (int)$m[2] - 1;
        $pane = $xs && $ys ? 'bottomRight' : ($ys ? 'bottomLeft' : 'topRight');
        $x .= '<sheetViews><sheetView workbookViewId="0"><pane'
            . ($xs ? " xSplit=\"$xs\"" : '') . ($ys ? " ySplit=\"$ys\"" : '')
            . " topLeftCell=\"{$sh['freeze']}\" activePane=\"$pane\" state=\"frozen\"/>"
            . "<selection pane=\"$pane\" activeCell=\"{$sh['freeze']}\" sqref=\"{$sh['freeze']}\"/></sheetView></sheetViews>";
    }
    $x .= '<sheetFormatPr defaultRowHeight="15"/>';
    if (!empty($sh['widths'])) {
        $x .= '<cols>';
        foreach ($sh['widths'] as $c => $w) $x .= "<col min=\"$c\" max=\"$c\" width=\"$w\" customWidth=\"1\"/>";
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    $rows = $sh['rows'] ?? [];
    ksort($rows);
    foreach ($rows as $r => $cells) {
        ksort($cells);
        $ht = isset($sh['heights'][$r]) ? ' ht="' . $sh['heights'][$r] . '" customHeight="1"' : '';
        $x .= "<row r=\"$r\"$ht>";
        foreach ($cells as $c => $cell) $x .= xlsx_cell_xml(xlsx_ref($c, $r), $cell);
        $x .= '</row>';
    }
    $x .= '</sheetData>';
    if (!empty($sh['filter'])) $x .= '<autoFilter ref="' . $sh['filter'] . '"/>';
    if (!empty($sh['merges'])) {
        $x .= '<mergeCells count="' . count($sh['merges']) . '">';
        foreach ($sh['merges'] as $mg) $x .= "<mergeCell ref=\"$mg\"/>";
        $x .= '</mergeCells>';
    }
    $prio = 1;
    foreach ($sh['cf'] ?? [] as [$sqref, $rules]) {
        $x .= "<conditionalFormatting sqref=\"$sqref\">";
        foreach ($rules as $rule) $x .= str_replace('{p}', (string)$prio++, $rule);
        $x .= '</conditionalFormatting>';
    }
    if (!empty($sh['validations'])) {
        $x .= '<dataValidations count="' . count($sh['validations']) . '">' . implode('', $sh['validations']) . '</dataValidations>';
    }
    $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
    return $x . '</worksheet>';
}

function xlsx_styles_xml(): string {
    $border = '<left style="thin"><color rgb="FFD0D7E2"/></left><right style="thin"><color rgb="FFD0D7E2"/></right>'
            . '<top style="thin"><color rgb="FFD0D7E2"/></top><bottom style="thin"><color rgb="FFD0D7E2"/></bottom><diagonal/>';
    // fontId, fillId, borderId, numFmtId, выравнивание
    $xf = [
        [0, 0, 0, 0, ''],                                                     // 0 default
        [2, 0, 0, 0, ''],                                                     // 1 title
        [1, 2, 1, 0, '<alignment horizontal="center" vertical="center" wrapText="1"/>'],   // 2 head
        [3, 3, 1, 0, '<alignment horizontal="center" vertical="center" wrapText="1"/>'],   // 3 group
        [4, 0, 0, 0, '<alignment horizontal="right"/>'],                     // 4 label
        [1, 4, 1, 0, '<alignment horizontal="center"/>'],                    // 5 param
        [0, 0, 1, 164, '<alignment horizontal="center"/>'],                  // 6 pct
        [0, 0, 1, 0, '<alignment horizontal="center"/>'],                    // 7 score
        [1, 0, 1, 164, '<alignment horizontal="center"/>'],                  // 8 pct_bold
        [1, 0, 1, 0, '<alignment horizontal="center"/>'],                    // 9 verdict
        [4, 0, 0, 0, ''],                                                     // 10 hint
        [0, 0, 1, 1, '<alignment horizontal="center"/>'],                    // 11 int
        [0, 0, 1, 0, ''],                                                     // 12 name
        [0, 4, 1, 0, '<alignment horizontal="center"/>'],                    // 13 input
    ];
    $xfs = '';
    foreach ($xf as [$fo, $fi, $bo, $nf, $al]) {
        $xfs .= "<xf numFmtId=\"$nf\" fontId=\"$fo\" fillId=\"$fi\" borderId=\"$bo\" xfId=\"0\""
             . ($nf ? ' applyNumberFormat="1"' : '') . ($fo ? ' applyFont="1"' : '') . ($fi ? ' applyFill="1"' : '')
             . ($bo ? ' applyBorder="1"' : '') . ($al ? ' applyAlignment="1">' . $al . '</xf>' : '/>');
    }
    $fill = fn($rgb) => "<fill><patternFill patternType=\"solid\"><fgColor rgb=\"FF$rgb\"/><bgColor indexed=\"64\"/></patternFill></fill>";
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0"/></numFmts>'
        . '<fonts count="5">'
        . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FF004B88"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . $fill('E2EEF9') . $fill('004B88') . $fill('FBF3E4') . '</fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $border . '</border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($xf) . '">' . $xfs . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '<dxfs count="3">'
        . '<dxf><font><b/><color rgb="FF006100"/></font><fill><patternFill><bgColor rgb="FFC6EFCE"/></patternFill></fill></dxf>'
        . '<dxf><font><b/><color rgb="FF9C0006"/></font><fill><patternFill><bgColor rgb="FFFFC7CE"/></patternFill></fill></dxf>'
        . '<dxf><font><color rgb="FFC00000"/></font></dxf>'
        . '</dxfs>'
        . '</styleSheet>';
}

/** Собрать книгу и отдать браузеру как файл */
function xlsx_send(string $filename, array $sheets, string $asciiName = 'export.xlsx'): void {
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $n = count($sheets);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $wbSheets = '';
    $defined = '';
    foreach (array_values($sheets) as $i => $sh) {
        $k = $i + 1;
        $ct .= "<Override PartName=\"/xl/worksheets/sheet$k.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $wbRels .= "<Relationship Id=\"rId$k\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$k.xml\"/>";
        $wbSheets .= '<sheet name="' . xlsx_esc($sh['name']) . "\" sheetId=\"$k\" r:id=\"rId$k\"/>";
        if (!empty($sh['filter'])) {
            $abs = preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $sh['filter']);
            $defined .= "<definedName name=\"_xlnm._FilterDatabase\" localSheetId=\"$i\" hidden=\"1\">'" . xlsx_esc(str_replace("'", "''", $sh['name'])) . "'!$abs</definedName>";
        }
        $zip->addFromString("xl/worksheets/sheet$k.xml", xlsx_sheet_xml($sh));
    }
    $ct .= '</Types>';
    $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '</Relationships>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xlsx_esc($filename) . '</dc:title><dc:creator>СМСО «Эпсилон»</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $wbSheets . '</sheets>' . ($defined ? "<definedNames>$defined</definedNames>" : '')
        . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', xlsx_styles_xml());
    $zip->close();

    while (ob_get_level()) ob_end_clean();
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $asciiName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, no-cache');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
