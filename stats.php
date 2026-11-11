<?php
require_once 'functions.php';
require_role('admin');

$id  = (int)($_GET['id'] ?? 0);
$att = get_attestation($pdo, $id);
if (!$att) {
    flash('Аттестация не найдена', 'err');
    redirect('manage.php');
}
$back = "attestation.php?id=$id#results";
$finished = $att['status'] !== 'draft';
$matrix = attestation_matrix($pdo, $id);

$total = count($matrix['rows']);
$vc = ['pass' => 0, 'fail' => 0, 'pending' => 0];
foreach ($matrix['rows'] as $r) $vc[$r['verdict']]++;
if ($finished) { $vc['fail'] += $vc['pending']; $vc['pending'] = 0; }

// По станциям
$stStats = [];
foreach ($matrix['stations'] as $sid => $s) {
    $done = 0; $passed = 0; $pctSum = 0.0;
    foreach ($matrix['rows'] as $r) {
        $c = $r['cells'][$sid] ?? null;
        if (!$c || $c['passed'] === null) continue;
        $done++;
        if ($c['passed']) $passed++;
        $pctSum += pct($c['sum'], $s['max']);
    }
    $stStats[$sid] = $s + [
        'done' => $done, 'passed' => $passed,
        'pass_rate' => $done ? round($passed / $done * 100) : null,
        'avg_pct' => $done ? round($pctSum / $done, 1) : null,
    ];
}

// По критериям: средний балл (0…1)
$cq = $pdo->prepare("
    SELECT c.id, c.name, c.station_id, AVG(r.score) AS avg_score, COUNT(r.id) AS n
    FROM criteria c
    JOIN stations s ON s.id = c.station_id
    LEFT JOIN results r ON r.criteria_id = c.id
    WHERE s.attestation_id = ?
    GROUP BY c.id, c.name, c.station_id
    ORDER BY c.station_id, c.id
");
$cq->execute([$id]);
$critByStation = []; $hardest = [];
foreach ($cq->fetchAll() as $c) {
    $critByStation[(int)$c['station_id']][] = $c;
    if ((int)$c['n'] > 0) $hardest[] = $c;
}
// Распределение аттестуемых по числу сданных станций (0…все)
$stN = $matrix['st_count'];
$byStations = $matrix['mode'] === 'stations';
if ($byStations) {
    // число человек по количеству сданных станций (0…все)
    $buckets = array_fill(0, $stN + 1, 0);
    foreach ($matrix['rows'] as $row) $buckets[min($stN, $row['st_passed'])]++;
    $thrPos = $stN ? round($matrix['needed'] / ($stN + 1) * 100, 2) : null;
} else {
    // число человек по среднему % станций, интервалы по 10%
    $buckets = array_fill(0, 10, 0);
    foreach ($matrix['rows'] as $row) $buckets[min(9, (int)floor($row['pct'] / 10))]++;
    $thrPos = $matrix['threshold'];
}
$bucketMax = max(1, max($buckets));

usort($hardest, fn($a, $b) => (float)$a['avg_score'] <=> (float)$b['avg_score']);
$hardest = array_slice(array_filter($hardest, fn($c) => (float)$c['avg_score'] < 1), 0, 5);

page_start('Статистика', ['back' => $back]);
?>
<section class="page-head">
    <div>
        <h1>Статистика</h1>
        <div class="muted"><?= e($att['title']) ?></div>
    </div>
    <a class="btn" href="<?= e($back) ?>">← Назад к результатам</a>
</section>

<?php if (!$total): ?>
    <div class="empty">Пока нет результатов — статистика появится, когда инструкторы начнут оценивать.</div>
<?php else: ?>
    <div class="stat-grid">
        <div class="stat card"><div class="stat-num"><?= $total ?></div><div class="muted">аттестуемых</div></div>
        <div class="stat card good"><div class="stat-num"><?= $vc['pass'] ?></div><div class="muted">сдали</div></div>
        <div class="stat card low"><div class="stat-num"><?= $vc['fail'] ?></div><div class="muted">не сдали</div></div>
        <?php if (!$finished): ?>
            <div class="stat card"><div class="stat-num"><?= $vc['pending'] ?></div><div class="muted">в процессе</div></div>
        <?php endif; ?>
        <div class="stat card"><div class="stat-num"><?= $total ? round($vc['pass'] / $total * 100) : 0 ?>%</div><div class="muted">доля сдавших</div></div>
        <?php if ($matrix['mode'] === 'stations'): ?>
            <div class="stat card"><div class="stat-num"><?= $matrix['needed'] ?>/<?= $stN ?></div><div class="muted">станций нужно сдать</div></div>
        <?php else: ?>
            <div class="stat card"><div class="stat-num"><?= $matrix['threshold'] ?>%</div><div class="muted">порог среднего % станций</div></div>
        <?php endif; ?>
    </div>

    <div class="charts-row">
        <figure class="card chart">
            <figcaption><b>Доля сдавших по станциям</b><span class="muted small">% от оценённых на станции</span></figcaption>
            <div class="colchart" role="img" aria-label="Доля сдавших по станциям">
                <div class="colchart-grid"><span>100%</span><span>50%</span><span>0%</span></div>
                <div class="colchart-cols">
                    <?php foreach ($stStats as $st): $v = $st['pass_rate']; ?>
                        <div class="col" tabindex="0">
                            <div class="col-track">
                                <div class="col-bar" style="height: <?= $v === null ? 0 : max(1, $v) ?>%"><span class="col-val"><?= $v === null ? '—' : $v . '%' ?></span></div>
                            </div>
                            <div class="col-label" title="<?= e($st['name']) ?>"><?= e($st['name']) ?></div>
                            <div class="col-tip"><b><?= e($st['name']) ?></b><br>сдали <?= $st['passed'] ?> из <?= $st['done'] ?> · порог <?= $st['threshold'] ?>%</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </figure>

        <figure class="card chart">
            <figcaption><b>Итоги аттестуемых</b><span class="muted small"><?= $byStations ? 'число человек по количеству сданных станций' : 'число человек по среднему % станций' ?> · <?= e(verdict_rule($matrix)) ?></span></figcaption>
            <div class="colchart" role="img" aria-label="Распределение итогов аттестуемых">
                <div class="colchart-grid"><span><?= $bucketMax ?></span><span></span><span>0</span></div>
                <div class="colchart-cols hist <?= $byStations ? 'hist-int' : '' ?>">
                    <?php if ($thrPos !== null): ?><div class="threshold-line" style="left: <?= $thrPos ?>%"><span>порог</span></div><?php endif; ?>
                    <?php foreach ($buckets as $i => $n): ?>
                        <div class="col <?= $byStations ? ($i < $matrix['needed'] ? 'below' : '') : (($i + 1) * 10 <= $matrix['threshold'] ? 'below' : '') ?>" tabindex="0">
                            <div class="col-track">
                                <div class="col-bar" style="height: <?= $n ? max(2, round($n / $bucketMax * 100)) : 0 ?>%"><?php if ($n): ?><span class="col-val"><?= $n ?></span><?php endif; ?></div>
                            </div>
                            <div class="col-label"><?= $byStations ? $i : $i * 10 ?></div>
                            <div class="col-tip"><?= $byStations ? 'Сдано станций: ' . $i . ' из ' . $stN : $i * 10 . '–' . ($i === 9 ? 100 : $i * 10 + 9) . '%' ?> — <?= plural($n, 'человек', 'человека', 'человек') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </figure>
    </div>

    <h2>По станциям</h2>
    <div class="table-wrap">
        <table class="simple stats-table">
            <thead><tr><th>Станция</th><th>Порог</th><th>Оценено</th><th>Сдали</th><th style="width:34%">Доля сдавших</th><th>Средний результат</th></tr></thead>
            <tbody>
            <?php foreach ($stStats as $s): ?>
                <tr>
                    <td><b><?= e($s['name']) ?></b></td>
                    <td><?= $s['threshold'] ?>%</td>
                    <td><?= $s['done'] ?></td>
                    <td><?= $s['passed'] ?></td>
                    <td>
                        <?php if ($s['pass_rate'] === null): ?><span class="muted">—</span>
                        <?php else: ?>
                            <div class="bar"><span class="<?= $s['pass_rate'] >= 80 ? 'good' : ($s['pass_rate'] >= 50 ? 'mid' : 'low') ?>" style="width: <?= $s['pass_rate'] ?>%"></span></div>
                            <span class="small"><?= $s['pass_rate'] ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $s['avg_pct'] === null ? '—' : fmt_pct($s['avg_pct']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($hardest): ?>
        <h2>Самые сложные критерии</h2>
        <div class="card">
            <?php foreach ($hardest as $c): $p = round((float)$c['avg_score'] * 100); ?>
                <div class="crit-stat">
                    <div><b><?= e($c['name']) ?></b> <span class="muted small">· <?= e($matrix['stations'][(int)$c['station_id']]['name'] ?? '') ?></span></div>
                    <div class="bar"><span class="<?= $p >= 80 ? 'good' : ($p >= 50 ? 'mid' : 'low') ?>" style="width: <?= max(2, $p) ?>%"></span></div>
                    <span class="small">средний балл <?= fmt_score(round((float)$c['avg_score'], 2)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2>Критерии по станциям</h2>
    <div class="tiles">
        <?php foreach ($matrix['stations'] as $sid => $s): ?>
            <div class="card station-card">
                <b class="tile-title"><?= e($s['name']) ?></b>
                <?php foreach ($critByStation[$sid] ?? [] as $c):
                    $has = (int)$c['n'] > 0; $p = $has ? round((float)$c['avg_score'] * 100) : 0; ?>
                    <div class="crit-stat">
                        <div class="small"><?= e($c['name']) ?></div>
                        <div class="bar"><span class="<?= $p >= 80 ? 'good' : ($p >= 50 ? 'mid' : 'low') ?>" style="width: <?= $has ? max(2, $p) : 0 ?>%"></span></div>
                        <span class="small muted"><?= $has ? fmt_score(round((float)$c['avg_score'], 2)) : '—' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php page_end();
