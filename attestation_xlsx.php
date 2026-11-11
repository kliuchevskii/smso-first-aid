<?php
/*
 * Выгрузка результатов аттестации в Excel с формулами.
 *   Лист «Итоги»    — баллы станций (ссылки на второй лист), % станций, сдано станций, средний %, итог.
 *                     Порог аттестации и режим итога — ячейки-параметры вверху: поменяете их — итог пересчитается.
 *   Лист «Критерии» — балл за каждый критерий; сумма станции считается формулой.
 * Требует xlsx.php. Вызывается из attestation.php (?export=xlsx).
 */
require_once __DIR__ . '/xlsx.php';

function attestation_xlsx(PDO $pdo, array $att, array $m): void {
    $S = XLSX_STYLES;
    $attId = (int)$att['id'];
    $stations = $m['stations'];
    $N = count($stations);
    $live = in_array($att['status'], ['prep', 'draft'], true);   // аттестация идёт — бывает «В процессе»

    // критерии станций и баллы по ним
    $crit = [];
    if ($stations) {
        $in = implode(',', array_map('intval', array_keys($stations)));
        foreach ($pdo->query("SELECT id, station_id, name FROM criteria WHERE station_id IN ($in) ORDER BY id") as $c) {
            $crit[(int)$c['station_id']][] = ['id' => (int)$c['id'], 'name' => $c['name']];
        }
    }
    $scores = [];
    $q = $pdo->prepare("SELECT r.participant_id, r.criteria_id, r.score FROM results r JOIN stations s ON s.id = r.station_id WHERE s.attestation_id = ?");
    $q->execute([$attId]);
    foreach ($q->fetchAll() as $r) $scores[(int)$r['participant_id']][(int)$r['criteria_id']] = (float)$r['score'];

    $names = [];
    $pq = $pdo->prepare("SELECT id, last_name, first_name, middle_name FROM participants WHERE id = ?");
    foreach (array_keys($m['rows']) as $pid) {
        $pq->execute([$pid]);
        $p = $pq->fetch();
        $names[$pid] = $p ? trim($p['last_name'] . ' ' . $p['first_name'] . ' ' . ($p['middle_name'] ?? '')) : $m['rows'][$pid]['name'];
    }

    /* ---------- лист «Критерии» ---------- */
    $cs = 'Критерии';
    $CR0 = 6;                                   // первая строка данных
    $c2 = ['rows' => [], 'widths' => [1 => 6, 2 => 34], 'merges' => [], 'cf' => []];
    $c2['rows'][1][1] = ['v' => 'Аттестация «' . $att['title'] . '» — баллы по критериям', 's' => $S['title']];
    $c2['rows'][2][1] = ['v' => 'Балл за критерий — от 0 до 1. Измените балл здесь — пересчитаются сумма станции и лист «Итоги».', 's' => $S['hint']];
    $c2['rows'][5][1] = ['v' => '№', 's' => $S['head']];
    $c2['rows'][5][2] = ['v' => 'Аттестуемый', 's' => $S['head']];
    $c2['rows'][4][1] = ['v' => '', 's' => $S['head']];
    $c2['rows'][4][2] = ['v' => 'Станция →', 's' => $S['head']];
    $sumCol = [];                               // станция → столбец суммы на листе «Критерии»
    $col = 3;
    foreach ($stations as $sid => $s) {
        $first = $col;
        foreach ($crit[$sid] ?? [] as $c) {
            $c2['rows'][5][$col] = ['v' => $c['name'], 's' => $S['head']];
            $c2['rows'][4][$col] = ['v' => '', 's' => $S['group']];
            $c2['widths'][$col] = 12;
            $col++;
        }
        $c2['rows'][4][$first] = ['v' => $s['name'], 's' => $S['group']];
        $c2['rows'][4][$col] = ['v' => '', 's' => $S['group']];
        $c2['rows'][5][$col] = ['v' => 'Сумма (из ' . $s['max'] . ')', 's' => $S['head']];
        $c2['widths'][$col] = 10;
        $c2['merges'][] = xlsx_ref($first, 4) . ':' . xlsx_ref($col, 4);
        $sumCol[$sid] = [$first, $col];
        $col++;
    }
    $c2['heights'][5] = 60;
    $last2 = $CR0 + max(count($m['rows']), 1) - 1;
    $r = $CR0;
    foreach ($m['rows'] as $pid => $row) {
        $c2['rows'][$r][1] = ['v' => participant_num($pdo, $attId, $pid) ?: '', 's' => $S['int']];
        $c2['rows'][$r][2] = ['v' => $names[$pid], 's' => $S['name']];
        foreach ($stations as $sid => $s) {
            [$first, $sc] = $sumCol[$sid];
            $cc = $first;
            foreach ($crit[$sid] ?? [] as $c) {
                $v = $scores[$pid][$c['id']] ?? null;
                $c2['rows'][$r][$cc] = ['v' => $v, 's' => $S['input']];
                $cc++;
            }
            $rng = xlsx_ref($first, $r) . ':' . xlsx_ref($sc - 1, $r);
            $cell = $row['cells'][$sid] ?? null;
            $c2['rows'][$r][$sc] = ['f' => "IF(COUNT($rng)=0,\"\",SUM($rng))", 'v' => $cell ? $cell['sum'] : '', 's' => $S['pct_bold']];
        }
        $r++;
    }
    if ($col > 3) {
        $c2['freeze'] = xlsx_ref(3, $CR0);
        $c2['validations'][] = '<dataValidation type="decimal" allowBlank="1" showErrorMessage="1" errorTitle="Балл" error="Балл за критерий — число от 0 до 1"'
            . ' sqref="' . xlsx_ref(3, $CR0) . ':' . xlsx_ref($col - 1, $last2) . '"><formula1>0</formula1><formula2>1</formula2></dataValidation>';
    }

    /* ---------- лист «Итоги» ---------- */
    $R0 = 10;                                   // первая строка данных
    $sh = ['rows' => [], 'widths' => [1 => 6, 2 => 34], 'merges' => [], 'cf' => []];
    $sh['rows'][1][1] = ['v' => 'Аттестация «' . $att['title'] . '» — итоги', 's' => $S['title']];
    $sh['rows'][2][2] = ['v' => 'Порог аттестации, %', 's' => $S['label']];
    $sh['rows'][2][3] = ['v' => (int)$m['threshold'], 's' => $S['param']];
    $sh['rows'][3][2] = ['v' => 'Режим итога', 's' => $S['label']];
    $sh['rows'][3][3] = ['v' => $m['mode'] === 'stations' ? 'по станциям' : 'средний %', 's' => $S['param']];
    $sh['rows'][4][2] = ['v' => 'Нужно сдать станций', 's' => $S['label']];
    $sh['rows'][4][3] = ['f' => "MAX(1,ROUNDUP($N*C2/100-0.000000001,0))", 'v' => (int)$m['needed'], 's' => $S['param']];
    $sh['rows'][2][5] = ['v' => '«средний %» — сдал, если среднее процентов станций не ниже порога (каждая станция = 100%, неоценённая = 0%).', 's' => $S['hint']];
    $sh['rows'][3][5] = ['v' => '«по станциям» — сдал, если сдано не меньше станций, чем в строке «Нужно сдать». Режим и порог можно менять.', 's' => $S['hint']];
    $sh['rows'][4][5] = ['v' => 'Станция сдана, если её % не ниже порога станции. Баллы берутся с листа «Критерии».', 's' => $S['hint']];
    $sh['validations'][] = '<dataValidation type="list" allowBlank="0" showErrorMessage="1" sqref="C3"><formula1>"средний %,по станциям"</formula1></dataValidation>';
    $sh['validations'][] = '<dataValidation type="whole" allowBlank="0" showErrorMessage="1" error="Порог — целое число от 1 до 100" sqref="C2"><formula1>1</formula1><formula2>100</formula2></dataValidation>';

    $sh['rows'][7][2] = ['v' => 'Максимум баллов →', 's' => $S['label']];
    $sh['rows'][8][2] = ['v' => 'Порог станции, % →', 's' => $S['label']];
    $sh['rows'][9][1] = ['v' => '№', 's' => $S['head']];
    $sh['rows'][9][2] = ['v' => 'Аттестуемый', 's' => $S['head']];
    $sh['rows'][6][1] = ['v' => '', 's' => $S['head']];
    $sh['rows'][6][2] = ['v' => 'Станция →', 's' => $S['head']];
    $scoreC = []; $pctC = [];
    $col = 3;
    foreach ($stations as $sid => $s) {
        $scoreC[$sid] = $col; $pctC[$sid] = $col + 1;
        $sh['rows'][6][$col] = ['v' => $s['name'], 's' => $S['group']];
        $sh['rows'][6][$col + 1] = ['v' => '', 's' => $S['group']];
        $sh['merges'][] = xlsx_ref($col, 6) . ':' . xlsx_ref($col + 1, 6);
        $sh['rows'][7][$col] = ['v' => (int)$s['max'], 's' => $S['param']];
        $sh['rows'][8][$col + 1] = ['v' => (int)$s['threshold'], 's' => $S['param']];
        $sh['rows'][9][$col] = ['v' => 'Баллы', 's' => $S['head']];
        $sh['rows'][9][$col + 1] = ['v' => '%', 's' => $S['head']];
        $sh['widths'][$col] = 9; $sh['widths'][$col + 1] = 9;
        $col += 2;
    }
    $cPassed = $col; $cRes = $col + 1; $cTotal = $col + 2; $cVerdict = $col + 3;
    foreach ([[$cPassed, 'Сдано станций', 10], [$cRes, 'Результат, % (среднее)', 12], [$cTotal, 'Баллы всего (из ' . $m['max_total'] . ')', 11], [$cVerdict, 'Итог', 13]] as [$c, $t, $w]) {
        $sh['rows'][9][$c] = ['v' => $t, 's' => $S['head']];
        $sh['rows'][6][$c] = ['v' => '', 's' => $S['head']];
        $sh['widths'][$c] = $w;
    }
    $sh['heights'][6] = 32; $sh['heights'][9] = 32;
    $sh['widths'][3] = 13;   // в этом столбце ещё и параметры (режим итога)

    $r = $R0;
    foreach ($m['rows'] as $pid => $row) {
        $r2 = $CR0 + ($r - $R0);
        $sh['rows'][$r][1] = ['v' => participant_num($pdo, $attId, $pid) ?: '', 's' => $S['int']];
        $sh['rows'][$r][2] = ['v' => $names[$pid], 's' => $S['name']];
        $passTerms = []; $pctRefs = []; $scoreRefs = [];
        foreach ($stations as $sid => $s) {
            $cell = $row['cells'][$sid] ?? null;
            $sc = xlsx_ref($scoreC[$sid], $r);
            $pc = xlsx_ref($pctC[$sid], $r);
            $src = "'$cs'!" . xlsx_ref($sumCol[$sid][1], $r2);
            $sh['rows'][$r][$scoreC[$sid]] = ['f' => $src, 'v' => $cell ? $cell['sum'] : '', 's' => $S['score']];
            $mx = xlsx_col($scoreC[$sid]) . '$7';
            $sh['rows'][$r][$pctC[$sid]] = ['f' => "IF($sc=\"\",\"\",ROUND($sc/$mx*100,1))", 'v' => $cell ? pct($cell['sum'], $s['max']) : '', 's' => $S['pct']];
            $passTerms[] = "IF($pc=\"\",0,IF($pc>=" . xlsx_col($pctC[$sid]) . '$8,1,0))';
            $pctRefs[] = $pc; $scoreRefs[] = $sc;
        }
        $pp = xlsx_ref($cPassed, $r); $rr = xlsx_ref($cRes, $r);
        $scList = implode(',', $scoreRefs) ?: '0';
        $pctList = implode(',', $pctRefs) ?: '0';
        $passedV = 0;
        foreach ($stations as $sid => $s) {
            $cell = $row['cells'][$sid] ?? null;
            if ($cell && pct($cell['sum'], $s['max']) >= $s['threshold']) $passedV++;
        }
        $sh['rows'][$r][$cPassed] = ['f' => implode('+', $passTerms) ?: '0', 'v' => $passedV, 's' => $S['int']];
        $sh['rows'][$r][$cRes] = ['f' => $N ? "ROUND(SUM($pctList)/$N,1)" : '0', 'v' => (float)$row['pct'], 's' => $S['pct_bold']];
        $sh['rows'][$r][$cTotal] = ['f' => "SUM($scList)", 'v' => (float)$row['total'], 's' => $S['score']];
        $passCond = "IF(\$C\$3=\"по станциям\",$pp>=\$C\$4,$rr>=\$C\$2)";
        if ($live && $N) {
            $failCond = "OR(COUNT($scList)=$N,IF(\$C\$3=\"по станциям\",$pp+$N-COUNT($scList)<\$C\$4,(SUM($pctList)+100*($N-COUNT($scList)))/$N<\$C\$2))";
            $f = "IF($passCond,\"Сдал\",IF($failCond,\"Не сдал\",\"В процессе\"))";
        } else {
            $f = "IF($passCond,\"Сдал\",\"Не сдал\")";
        }
        $vv = $row['verdict'] === 'pass' ? 'Сдал' : ($row['verdict'] === 'fail' || !$live ? 'Не сдал' : 'В процессе');
        $sh['rows'][$r][$cVerdict] = ['f' => $f, 'v' => $vv, 's' => $S['verdict']];
        $r++;
    }
    $last = max($R0, $r - 1);
    $sh['freeze'] = xlsx_ref(3, $R0);
    if ($m['rows']) $sh['filter'] = 'A9:' . xlsx_ref($cVerdict, $last);
    $vr = xlsx_ref($cVerdict, $R0) . ':' . xlsx_ref($cVerdict, $last);
    $sh['cf'][] = [$vr, [
        '<cfRule type="cellIs" dxfId="0" priority="{p}" operator="equal"><formula>"Сдал"</formula></cfRule>',
        '<cfRule type="cellIs" dxfId="1" priority="{p}" operator="equal"><formula>"Не сдал"</formula></cfRule>',
    ]];
    foreach ($stations as $sid => $s) {
        $c = xlsx_col($pctC[$sid]);
        $sh['cf'][] = ["{$c}$R0:{$c}$last", ["<cfRule type=\"expression\" dxfId=\"2\" priority=\"{p}\"><formula>AND(ISNUMBER({$c}$R0),{$c}$R0&lt;{$c}\$8)</formula></cfRule>"]];
    }

    $sh['name'] = 'Итоги';
    $c2['name'] = $cs;
    $title = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $att['title'])) ?: 'аттестация';
    xlsx_send('Аттестация ' . $title . '.xlsx', [$sh, $c2], 'attestation_' . $attId . '.xlsx');
}
