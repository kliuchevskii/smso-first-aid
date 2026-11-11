<?php
require_once 'functions.php';
require_role('admin');

$attId = (int)($_GET['att'] ?? 0);
$pid   = (int)($_GET['p'] ?? 0);
$att   = get_attestation($pdo, $attId);
$pq = $pdo->prepare("SELECT * FROM participants WHERE id = ?");
$pq->execute([$pid]);
$person = $pq->fetch();
if (!$att || !$person) {
    flash('Аттестуемый не найден', 'err');
    redirect($att ? "attestation.php?id=$attId#results" : 'index.php');
}
$back = "attestation.php?id=$attId#results";

// Станции, где у человека есть результаты
$sq = $pdo->prepare("
    SELECT DISTINCT s.id, s.name, s.pass_threshold
    FROM results r JOIN stations s ON s.id = r.station_id
    WHERE r.participant_id = ? AND s.attestation_id = ?
    ORDER BY s.id
");
$sq->execute([$pid, $attId]);
$stations = $sq->fetchAll();

$rq = $pdo->prepare("
    SELECT r.station_id, r.criteria_id, r.score, u.full_name AS judge
    FROM results r
    JOIN stations s ON s.id = r.station_id
    LEFT JOIN users u ON u.id = r.instructor_id
    WHERE r.participant_id = ? AND s.attestation_id = ?
");
$rq->execute([$pid, $attId]);
$scores = []; $judges = [];
foreach ($rq->fetchAll() as $r) {
    $scores[(int)$r['station_id']][(int)$r['criteria_id']] = $r['score'];
    if ($r['judge']) $judges[(int)$r['station_id']][$r['judge']] = true;
}

$grand = 0.0; $grandMax = 0;
$matrix = attestation_matrix($pdo, $attId);
$verdict = $matrix['rows'][$pid]['verdict'] ?? 'pending';
$finished = $att['status'] !== 'draft';
page_start($person['last_name'] . ' ' . $person['first_name'], ['back' => $back, 'width' => 'narrow']);
?>
<section class="page-head">
    <div>
        <h1><?= e($person['last_name'] . ' ' . $person['first_name']) ?></h1>
        <div class="muted"><?= e($att['title']) ?></div>
    </div>
    <div class="big-verdict"><?= verdict_badge($verdict, $finished) ?></div>
</section>

<?php if (!$stations): ?>
    <div class="empty">По этой аттестации у аттестуемого нет результатов.</div>
<?php endif; ?>

<?php foreach ($stations as $s):
    $sid = (int)$s['id'];
    $crit = station_criteria($pdo, $sid);
    $sum = 0.0;
    foreach ($crit as $c) $sum += (float)($scores[$sid][(int)$c['id']] ?? 0);
    $max = count($crit);
    $grand += $sum; $grandMax += $max; ?>
    <article class="card">
        <div class="tile-top">
            <h2 class="mt0"><?= e($s['name']) ?></h2>
            <?php $cnt = count($scores[$sid] ?? []);
                  $passed = station_passed($sum, $cnt, $max, (int)$s['pass_threshold']); ?>
            <span class="score-pill <?= $passed === null ? 'mid' : ($passed ? 'good' : 'low') ?>"><?= fmt_score($sum) ?> / <?= $max ?></span>
        </div>
        <div class="muted small">
            <?= fmt_pct(pct($sum, $max)) ?> при пороге <?= (int)$s['pass_threshold'] ?>% —
            <b><?= $passed === null ? 'оценка не завершена' : ($passed ? 'сдал' : 'не сдал') ?></b>
        </div>
        <?php if (!empty($judges[$sid])): ?>
            <div class="muted small">Судья: <?= e(implode(', ', array_keys($judges[$sid]))) ?></div>
        <?php endif; ?>
        <table class="simple">
            <?php foreach ($crit as $i => $c):
                $v = $scores[$sid][(int)$c['id']] ?? null; ?>
                <tr>
                    <td class="num"><?= $i + 1 ?></td>
                    <td><?= e($c['name']) ?></td>
                    <td class="score <?= $v === null ? 'none' : ratio_class((float)$v, 1) ?>"><?= fmt_score($v) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </article>
<?php endforeach; ?>

<?php if ($stations): ?>
    <div class="card total-card">
        <span>Сдано станций
            <span class="muted small"><br><?= e(verdict_rule($matrix)) ?> · результат <?= fmt_pct($matrix['rows'][$pid]['pct'] ?? 0) ?> (среднее по станциям; баллов <?= fmt_score($grand) ?> из <?= $matrix['max_total'] ?>)</span></span>
        <span class="score-pill big <?= $verdict === 'pass' ? 'good' : ($verdict === 'fail' || $finished ? 'low' : '') ?>"><?= (int)($matrix['rows'][$pid]['st_passed'] ?? 0) ?> / <?= $matrix['st_count'] ?></span>
    </div>
<?php endif; ?>

<p><a class="btn" href="<?= e($back) ?>">← Назад к результатам</a></p>
<?php page_end();
