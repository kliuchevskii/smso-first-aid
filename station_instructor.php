<?php
require_once 'functions.php';
require_login();

$sid = (int)($_GET['id'] ?? $_POST['station'] ?? 0);
$st  = station_access($pdo, $sid);
if (!$st) {
    flash('Станция не найдена или у вас нет к ней доступа', 'err');
    redirect('index.php');
}
enter_instructor();   // сотрудник управления здесь работает как инструктор

$attId    = (int)$st['attestation_id'];
$isDraft  = $st['att_status'] === 'draft';
$approved = (int)$st['judge_approved'] === 1;
$criteria = station_criteria($pdo, $sid);
$canScore = $isDraft && $approved && $criteria;
$canEdit  = $isDraft && $st['can_edit'] && !att_started($pdo, $attId);
$threshold = (int)$st['pass_threshold'];
$att = get_attestation($pdo, $attId);
$byAdmin = roster_mode($att);   // аттестуемых вносит сотрудник управления или они регистрируются сами
$retake  = (int)$att['allow_retake'] === 1;            // повторное прохождение станции разрешено

/* ===== Выбор аттестуемого из списка (если список ведёт сотрудник управления) ===== */
if (is_post() && isset($_POST['pick_person'])) {
    $pid = (int)$_POST['pick_person'];
    if (!$canScore || !$byAdmin || !in_roster($pdo, $attId, $pid)) {
        flash('Этого аттестуемого нельзя выбрать', 'err');
        redirect("station_instructor.php?id=$sid");
    }
    if (!$retake && has_station_result($pdo, $sid, $pid)) {
        flash('Этот аттестуемый уже прошёл вашу станцию — повторное прохождение в этой аттестации запрещено', 'err');
        redirect("station_instructor.php?id=$sid");
    }
    redirect("score_wizard.php?station=$sid&participant=$pid");
}

/* ===== Новый аттестуемый ===== */
if (is_post() && isset($_POST['add_person'])) {
    if (!$canScore || $byAdmin) {
        flash($byAdmin ? ((int)$att['self_register'] ? 'Аттестуемые регистрируются сами — выберите человека из списка' : 'Аттестуемых вносит сотрудник управления — выберите человека из списка') : 'Сейчас нельзя добавлять аттестуемых на эту станцию', 'err');
        redirect("station_instructor.php?id=$sid");
    }
    try {
        $pid = find_or_create_participant($pdo, (string)($_POST['last_name'] ?? ''), (string)($_POST['first_name'] ?? ''));
        if (!$retake && has_station_result($pdo, $sid, $pid)) {
            flash('Этот аттестуемый уже прошёл вашу станцию — повторное прохождение в этой аттестации запрещено', 'err');
            redirect("station_instructor.php?id=$sid");
        }
        redirect("score_wizard.php?station=$sid&participant=$pid");
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
        redirect("station_instructor.php?id=$sid&new=1");
    }
}

$res = station_results($pdo, $sid);
$maxScore = count($criteria);

// Подсказки фамилий: уже известные аттестуемые этой аттестации
$known = [];
if ($canScore) {
    $kq = $pdo->prepare("
        SELECT DISTINCT p.last_name, p.first_name FROM participants p
        JOIN results r ON r.participant_id = p.id
        JOIN stations s ON s.id = r.station_id
        WHERE s.attestation_id = ?
        ORDER BY p.last_name
    ");
    $kq->execute([$attId]);
    $known = $kq->fetchAll();
}

page_start($st['name'], ['back' => "attestation_instructor.php?id=$attId"]);
?>
<section class="page-head">
    <div>
        <h1><?= e($st['name']) ?></h1>
        <div class="head-meta">
            <span class="muted"><?= e($st['att_title']) ?></span>
            <span class="badge info" title="Порог сдачи станции">порог <?= $threshold ?>%</span>
            <span class="badge <?= $approved ? 'ok' : 'warn' ?>"><?= $approved ? 'доступ открыт' : 'ждёт подтверждения' ?></span>
        </div>
    </div>
    <div class="btn-row">
        <?php if ($canEdit): ?>
            <a class="btn ghost" href="threshold.php?station=<?= $sid ?>">Порог сдачи</a>
            <a class="btn ghost" href="criteria_edit.php?station=<?= $sid ?>"><?= icon('edit') ?> Редактировать станцию</a>
        <?php endif; ?>
        <?php if ($canScore): ?>
            <?php if ($byAdmin): ?>
                <button type="button" class="btn primary" data-dialog="#pick-person"><?= icon('user') ?> Выбрать аттестуемого</button>
            <?php else: ?>
                <button type="button" class="btn primary" data-toggle="#new-person" data-focus="#last_name"><?= icon('plus') ?> Новый аттестуемый</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php if (!$isDraft): ?>
    <div class="alert warn">Аттестация завершена — изменение результатов закрыто.</div>
<?php elseif (!$approved): ?>
    <div class="alert warn">Сотрудник управления ещё не подтвердил станцию. Пока можно настроить критерии — оценивать аттестуемых станет можно после подтверждения.</div>
<?php elseif (!$criteria): ?>
    <div class="alert warn">На станции нет критериев. <a href="criteria_edit.php?station=<?= $sid ?>&add=1">Добавьте критерии</a>, чтобы начать оценку.</div>
<?php endif; ?>

<?php if ($canScore): ?>
    <?php if ($byAdmin): ?>
    <?php $roster = att_roster($pdo, $attId); ?>
    <dialog class="modal pick-modal" id="pick-person">
        <div class="modal-head">
            <h2 class="mt0">Выберите аттестуемого</h2>
            <button type="button" class="icon-btn" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button>
        </div>
        <?php if (!$roster): ?>
            <p class="muted"><?= (int)$att['self_register'] ? 'Пока никто не зарегистрировался. Аттестуемые регистрируются сами по QR-коду у сотрудника управления.' : 'Список аттестуемых пока пуст — его заполняет сотрудник управления.' ?></p>
        <?php else: ?>
            <input type="search" class="search full-w" placeholder="Фамилия или номер" data-filter="#pick-person .pick-person-row">
            <form method="post" class="pick-person-list">
                <input type="hidden" name="station" value="<?= $sid ?>">
                <?php foreach ($roster as $p): $passed = has_station_result($pdo, $sid, (int)$p['id']); ?>
                    <button name="pick_person" value="<?= (int)$p['id'] ?>" class="pick-person-row" <?= $passed && !$retake ? 'disabled' : '' ?>>
                        <span class="pp-name"><b><?= e(participant_name($p)) ?></b>
                            <span class="muted small">№<?= (int)$p['num'] ?><?= !empty($p['birth_date']) ? ' · ' . e(date('d.m.Y', strtotime($p['birth_date']))) : '' ?></span></span>
                        <?php if ($passed): ?><span class="badge <?= $retake ? 'info' : 'ok' ?>"><?= $retake ? 'пройдено · повторить' : 'пройдено' ?></span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>
        <div class="modal-actions"><button type="button" class="btn" data-dialog-close>← Назад</button></div>
    </dialog>
    <?php else: ?>
    <div id="new-person" class="card new-person" <?= isset($_GET['new']) ? '' : 'hidden' ?>>
        <h2 class="mt0">Новый аттестуемый</h2>
        <form method="post" class="person-form">
            <input type="hidden" name="station" value="<?= $sid ?>">
            <label>Фамилия
                <input id="last_name" name="last_name" list="known-last" autocomplete="off" required>
            </label>
            <label>Имя
                <input name="first_name" autocomplete="off" required>
            </label>
            <button name="add_person" value="1" class="btn primary">Добавить</button>
        </form>
        <p class="muted small">Регистр не важен: если такой человек уже проходил другие станции, баллы добавятся в его анкету.<?= $retake ? ' Повторное прохождение разрешено — засчитается последняя попытка.' : '' ?></p>
        <?php if ($known): ?>
            <datalist id="known-last">
                <?php foreach ($known as $k): ?><option value="<?= e($k['last_name']) ?>"><?= e($k['first_name']) ?></option><?php endforeach; ?>
            </datalist>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<section>
    <h2>Результаты станции <?php if ($res['participants']): ?><span class="muted">(<?= count($res['participants']) ?>)</span><?php endif; ?></h2>
    <?php if (!$res['participants']): ?>
        <div class="empty">Пока никого не оценивали.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="matrix">
                <thead>
                <tr>
                    <th class="sticky">Аттестуемый</th>
                    <?php foreach ($res['criteria'] as $i => $c): ?>
                        <th class="m-hide" title="<?= e($c['name']) ?>"><span class="th-num"><?= $i + 1 ?>.</span> <?= e($c['name']) ?></th>
                    <?php endforeach; ?>
                    <th class="m-hide">Σ<div class="th-sub">из <?= $maxScore ?></div></th>
                    <th>Результат<div class="th-sub">порог <?= $threshold ?>%</div></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($res['participants'] as $p):
                    $pid = (int)$p['pid'];
                    $sum = 0.0;
                    $url = "score_wizard.php?station=$sid&participant=$pid"; ?>
                    <tr class="row-link" data-detail="#rd-<?= $pid ?>" data-title="<?= e($p['last_name'] . ' ' . $p['first_name']) ?>">
                        <th class="sticky"><span class="name-link"><?= e($p['last_name'] . ' ' . $p['first_name']) ?></span></th>
                        <?php foreach ($res['criteria'] as $c):
                            $v = $res['scores'][$pid][(int)$c['id']] ?? null;
                            if ($v !== null) $sum += (float)$v; ?>
                            <td class="m-hide <?= $v === null ? 'none' : ratio_class((float)$v, 1) ?>"><?= fmt_score($v) ?></td>
                        <?php endforeach; ?>
                        <?php $cnt = count($res['scores'][$pid] ?? []);
                              $passed = station_passed($sum, $cnt, $maxScore, $threshold); ?>
                        <td class="total m-hide <?= $passed === null ? '' : ($passed ? 'good' : 'low') ?>"><?= fmt_score($sum) ?></td>
                        <td><?= $passed === null ? '<span class="badge">не завершено</span>' : ($passed ? '<span class="badge ok">Сдал</span>' : '<span class="badge err">Не сдал</span>') ?>
                            <div class="th-sub"><?= fmt_pct(pct($sum, $maxScore)) ?></div></td>
                    </tr>
                    <tr class="row-detail" id="rd-<?= $pid ?>" hidden>
                        <td colspan="<?= count($res['criteria']) + 3 ?>">
                            <ul class="detail-list">
                                <?php foreach ($res['criteria'] as $i => $c): $v = $res['scores'][$pid][(int)$c['id']] ?? null; ?>
                                    <li><span><?= $i + 1 ?>. <?= e($c['name']) ?></span>
                                        <b class="<?= $v === null ? 'none' : ratio_class((float)$v, 1) ?>"><?= fmt_score($v) ?></b></li>
                                <?php endforeach; ?>
                                <li class="detail-total"><span>Итого (<?= fmt_pct(pct($sum, $maxScore)) ?>)</span><b><?= fmt_score($sum) ?> / <?= $maxScore ?></b></li>
                            </ul>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canScore): ?><p class="muted small">Нажмите на строку, чтобы увидеть баллы по критериям.<?= $retake ? ' Повторное прохождение разрешено — засчитывается последняя попытка.' : ' Сохранённый результат не меняется.' ?></p><?php endif; ?>
    <?php endif; ?>
</section>
<?php page_end();
