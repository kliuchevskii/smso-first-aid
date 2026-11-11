<?php
require_once 'functions.php';
require_login();
enter_instructor();   // сотрудник управления здесь работает как инструктор

$sid = (int)($_GET['station'] ?? $_POST['station'] ?? 0);
$pid = (int)($_GET['participant'] ?? $_POST['participant'] ?? 0);
$st  = station_access($pdo, $sid);
$back = "station_instructor.php?id=$sid";

if (!$st) {
    flash('Нет доступа к станции', 'err');
    redirect('index.php');
}
if ($st['att_status'] !== 'draft' || !(int)$st['judge_approved']) {
    flash('Оценка на этой станции сейчас недоступна', 'err');
    redirect($back);
}
$criteria = station_criteria($pdo, $sid);
if (!$criteria) {
    flash('Сначала добавьте критерии', 'err');
    redirect($back);
}
$pq = $pdo->prepare("SELECT * FROM participants WHERE id = ?");
$pq->execute([$pid]);
$person = $pq->fetch();
if (!$person) {
    flash('Аттестуемый не найден', 'err');
    redirect($back);
}
$fio = $person['last_name'] . ' ' . $person['first_name'];

// Результат сохраняется один раз: исправлять можно только до нажатия «Сохранить результат».
// Если в аттестации разрешено повторное прохождение — новая попытка заменяет прежнюю.
$attRow = get_attestation($pdo, (int)$st['attestation_id']);
$retake = (int)$attRow['allow_retake'] === 1;
if (roster_mode($attRow) && !in_roster($pdo, (int)$st['attestation_id'], $pid)) {
    flash('Этого человека нет в списке аттестуемых', 'err');
    redirect($back);
}
if (!$retake && has_station_result($pdo, $sid, $pid)) {
    flash("Результат «{$fio}» на этой станции уже сохранён — изменить его нельзя", 'err');
    redirect($back);
}

/* ===== Сохранение ===== */
if (is_post()) {
    $in = (array)($_POST['score'] ?? []);
    $clean = [];
    foreach ($criteria as $c) {
        $cid = (int)$c['id'];
        $v = isset($in[$cid]) ? (string)$in[$cid] : null;
        if ($v === null || !in_array($v, parse_scores($c['allowed_scores']), true)) {
            flash('Выставьте баллы по всем критериям', 'err');
            redirect("score_wizard.php?station=$sid&participant=$pid");
        }
        $clean[$cid] = (float)$v;
    }
    $pdo->beginTransaction();
    try {
        if ($retake) {   // повторная попытка заменяет прежнюю
            $pdo->prepare("DELETE FROM results WHERE participant_id = ? AND station_id = ?")->execute([$pid, $sid]);
        }
        $ins = $pdo->prepare("INSERT INTO results (participant_id, station_id, criteria_id, instructor_id, score) VALUES (?, ?, ?, ?, ?)");
        foreach ($clean as $cid => $v) {
            $ins->execute([$pid, $sid, $cid, uid(), $v]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    $sum = array_sum($clean); $max = count($criteria);
    $passed = pct($sum, $max) >= (int)$st['pass_threshold'];
    flash("Результат сохранён: $fio — " . fmt_score($sum) . " из $max (" . fmt_pct(pct($sum, $max)) . '). '
        . ($passed ? 'Станция сдана ✓' : 'Станция не сдана ✗'), $passed ? 'ok' : 'err');
    redirect($back);
}

/* ===== Ранее сохранённые баллы ===== */
$saved = [];
$q = $pdo->prepare("SELECT criteria_id, score FROM results WHERE participant_id = ? AND station_id = ?");
$q->execute([$pid, $sid]);
foreach ($q->fetchAll() as $r) $saved[(int)$r['criteria_id']] = fmt_score($r['score']);
$saved = [];   // каждая попытка заполняется с чистого листа
$total = count($criteria);

page_start('Оценка: ' . $fio, ['back' => $back]);
?>
<section class="page-head">
    <div>
        <h1><?= e($fio) ?></h1>
        <div class="head-meta">
            <span class="muted">Станция «<?= e($st['name']) ?>»</span>
            <span class="badge info">порог сдачи <?= (int)$st['pass_threshold'] ?>%</span>

        </div>
    </div>
</section>

<form method="post" class="wizard" data-wizard data-threshold="<?= (int)$st['pass_threshold'] ?>">
    <input type="hidden" name="station" value="<?= $sid ?>">
    <input type="hidden" name="participant" value="<?= $pid ?>">

    <aside class="wizard-nav">
        <div class="wizard-progress"><span data-progress-bar></span></div>
        <div class="muted small" data-progress-text>Оценено 0 из <?= $total ?></div>
        <ol>
            <?php foreach ($criteria as $i => $c): ?>
                <li><button type="button" data-goto="<?= $i ?>">
                    <span class="nav-num"><?= $i + 1 ?></span>
                    <span class="nav-name"><?= e($c['name']) ?></span>
                    <span class="nav-val" data-nav-val="<?= $i ?>"></span>
                </button></li>
            <?php endforeach; ?>
        </ol>
    </aside>

    <div class="wizard-main">
        <?php foreach ($criteria as $i => $c):
            $cid = (int)$c['id'];
            $cur = $saved[$cid] ?? null; ?>
            <section class="step card" data-step="<?= $i ?>">
                <div class="step-count">Критерий <?= $i + 1 ?> из <?= $total ?></div>
                <h2 class="step-title"><?= e($c['name']) ?></h2>
                <div class="score-options">
                    <?php foreach (parse_scores($c['allowed_scores']) as $sc): ?>
                        <label class="score-opt">
                            <input type="radio" name="score[<?= $cid ?>]" value="<?= e($sc) ?>" <?= $cur !== null && $cur === $sc ? 'checked' : '' ?>>
                            <span><?= e($sc) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="wizard-controls">
            <button type="button" class="btn ghost" data-prev>← Назад</button>
            <button type="button" class="btn ghost" data-next>Пропустить →</button>
        </div>
        <p class="muted small center sw-tip">После выбора балла сразу откроется следующий критерий. Чтобы исправить оценку, вернитесь кнопкой «Назад» или выберите критерий в списке — до сохранения всё можно изменить.</p>
        <div class="verdict" data-verdict hidden role="alert">
            <div class="verdict-icon" data-verdict-icon></div>
            <div>
                <div class="verdict-title" data-verdict-title></div>
                <div class="verdict-sub" data-verdict-sub></div>
            </div>
        </div>
        <div class="wizard-save">
            <button class="btn primary big full" data-save><?= icon('save') ?> Сохранить результат</button>
            <p class="muted small center" data-save-hint>Кнопка станет активной, когда будут выставлены баллы по всем критериям.</p>
        </div>
    </div>
</form>
<?php page_end();
