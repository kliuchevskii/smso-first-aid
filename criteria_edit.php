<?php
// Критерии станции (шаг 2 мастера станции или отдельное редактирование)
require_once 'functions.php';
require_login();

$sid = (int)($_GET['station'] ?? $_POST['station'] ?? 0);
station_context($pdo, $sid);
$st  = station_access($pdo, $sid);
if (!$st) {
    flash('Станция не найдена или нет доступа', 'err');
    redirect('index.php');
}
$attId = (int)$st['attestation_id'];
$wiz   = !empty($_GET['wizard']);
$for   = (int)($_GET['for'] ?? 0);
$qs    = station_wiz_qs();
$back  = is_admin()
    ? (($_GET['ret'] ?? '') === 'wiz' ? "attestation_wizard.php?id=$attId&step=2" : "attestation.php?id=$attId#stations")
    : "station_instructor.php?id=$sid";
$self  = "criteria_edit.php?station=$sid$qs";
$thresholdUrl = "threshold.php?station=$sid$qs";

if (!att_editable($st['att_status'])) {
    flash('Аттестация завершена — станцию изменить нельзя', 'err');
    redirect($back);
}
if (!$st['can_edit']) {
    flash('Сотрудник управления запретил редактировать станции управления', 'err');
    redirect($back);
}

$scored  = station_scored_count($pdo, $sid);
$started = station_locked($pdo, $sid);   // приём на станции начался (или станция была до начала приёма)
$locked  = $started;   // такие критерии не меняются; станцию, добавленную позже, можно настроить до первой оценки

/* ======================== Действия ======================== */
if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_station') {
        $name = trim($_POST['station_name'] ?? '');
        if ($st['can_rename'] && $name !== '' && $name !== $st['name']) {
            $pdo->prepare("UPDATE stations SET name = ? WHERE id = ?")->execute([$name, $sid]);
        }
        $next = $_POST['next'] ?? '';
        if ($next === 'threshold') redirect($thresholdUrl);
        if ($next === 'finish') {
            flash(is_admin() && $for ? 'Станция создана. Назначьте инструктора.' : 'Станция сохранена');
            redirect(station_finish_url($st, $for));
        }
        flash('Станция сохранена');
        redirect($back);
    }

    if ($locked && in_array($action, ['save_criterion', 'delete_criterion'], true)) {
        flash(STARTED_MSG, 'err');
        redirect($self);
    }

    if ($action === 'save_criterion') {
        $cid    = (int)($_POST['criterion'] ?? 0);
        $name   = trim($_POST['crit_name'] ?? '');
        $scores = array_values(array_intersect(SCORE_OPTIONS, (array)($_POST['scores'] ?? [])));
        if ($name === '' || !$scores) {
            flash('Укажите название критерия и хотя бы один вариант баллов', 'err');
            redirect($self . ($cid ? "&edit=$cid" : '&add=1'));
        }
        $allowed = implode(',', $scores);
        if ($cid) {
            $pdo->prepare("UPDATE criteria SET name = ?, allowed_scores = ? WHERE id = ? AND station_id = ?")
                ->execute([$name, $allowed, $cid, $sid]);
            flash('Критерий обновлён');
            redirect($self);
        }
        $pdo->prepare("INSERT INTO criteria (station_id, name, allowed_scores) VALUES (?, ?, ?)")
            ->execute([$sid, $name, $allowed]);
        flash('Критерий добавлен');
        redirect($self . (isset($_POST['and_next']) ? '&add=1' : ''));
    }

    if ($action === 'delete_criterion') {
        $cid = (int)($_POST['criterion'] ?? 0);
        $pdo->prepare("DELETE FROM criteria WHERE id = ? AND station_id = ?")->execute([$cid, $sid]);
        flash('Критерий удалён', 'warn');
        redirect($self);
    }
    redirect($self);
}

/* ======================== Данные ======================== */
$criteria = station_criteria($pdo, $sid);
$editId   = (int)($_GET['edit'] ?? 0);
$editing  = null;
foreach ($criteria as $c) if ((int)$c['id'] === $editId) $editing = $c;
$showForm = !$locked && ($editing || isset($_GET['add']) || !$criteria);
$formScores = $editing ? parse_scores($editing['allowed_scores']) : ['0', '1'];

page_start('Станция: ' . $st['name'], ['back' => $back]);
?>
<?php if ($wiz) wizard_steps(2, $sid); ?>
<section class="page-head">
    <div>
        <h1><?= $wiz ? 'Критерии станции' : 'Редактирование станции' ?></h1>
        <div class="muted"><?= e($st['name']) ?> · <?= e($st['att_title']) ?></div>
    </div>
</section>

<?php if ($locked): ?>
    <div class="alert warn"><?= STARTED_MSG ?>, чтобы результаты аттестуемых оставались сопоставимыми.<?= $scored ? " На этой станции оценено: $scored." : '' ?></div>
<?php endif; ?>

<form method="post" id="station-form" action="<?= e($self) ?>">
    <input type="hidden" name="station" value="<?= $sid ?>">
    <input type="hidden" name="action" value="save_station">
</form>

<?php if (!$wiz): ?>
<div class="card">
    <label>Название станции
        <input form="station-form" name="station_name" value="<?= e($st['name']) ?>" required
            <?= $st['can_rename'] ? '' : 'readonly' ?>>
    </label>
    <?php if (!$st['can_rename']): ?>
        <p class="muted small">Название задано сотрудником управления и не меняется.</p>
    <?php endif; ?>
</div>
<?php else: ?>
    <input form="station-form" type="hidden" name="station_name" value="<?= e($st['name']) ?>">
<?php endif; ?>

<div class="card">
    <div class="section-head">
        <h2 class="mt0">Критерии <span class="muted">(<?= count($criteria) ?>)</span></h2>
        <?php if (!$showForm && !$locked): ?>
            <a class="btn primary" href="<?= $self ?>&add=1#crit-form" data-toggle="#crit-form"><?= icon('plus') ?> Создать критерий</a>
        <?php endif; ?>
    </div>

    <?php if (!$criteria): ?>
        <p class="muted">Критериев пока нет. Каждый критерий оценивается от 0 до 1 балла.</p>
    <?php else: ?>
        <ol class="crit-rows">
            <?php foreach ($criteria as $i => $c): ?>
                <li class="crit-row <?= $editing && (int)$c['id'] === $editId ? 'is-editing' : '' ?>">
                    <span class="crit-num"><?= $i + 1 ?></span>
                    <span class="crit-name"><?= e($c['name']) ?></span>
                    <span class="score-set"><?php foreach (parse_scores($c['allowed_scores']) as $sc): ?><i><?= e($sc) ?></i><?php endforeach; ?></span>
                    <?php if (!$locked): ?>
                        <span class="row-actions">
                            <a class="icon-btn" href="<?= $self ?>&edit=<?= (int)$c['id'] ?>#crit-form" title="Изменить"><?= icon('edit') ?></a>
                            <form method="post" class="inline" action="<?= e($self) ?>">
                                <input type="hidden" name="station" value="<?= $sid ?>">
                                <input type="hidden" name="criterion" value="<?= (int)$c['id'] ?>">
                                <button name="action" value="delete_criterion" class="icon-btn danger" title="Удалить"
                                        data-confirm="Удалить критерий «<?= e($c['name']) ?>»?"><?= icon('trash') ?></button>
                            </form>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if (!$locked): ?>
    <!-- Мастер создания критерия -->
    <div id="crit-form" class="crit-wizard" <?= $showForm ? '' : 'hidden' ?>>
        <h3 class="mt0"><?= $editing ? 'Изменить критерий' : 'Новый критерий' ?></h3>
        <form method="post" class="stack" action="<?= e($self) ?>">
            <input type="hidden" name="station" value="<?= $sid ?>">
            <input type="hidden" name="criterion" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
            <input type="hidden" name="action" value="save_criterion">
            <label>Название критерия
                <input name="crit_name" value="<?= e($editing['name'] ?? '') ?>"
                       placeholder="Например: Проверил безопасность места" required <?= $showForm ? 'data-autofocus' : '' ?>>
            </label>
            <fieldset class="score-pick" data-select-all-group data-require-one>
                <legend>Какие баллы можно выставить</legend>
                <div class="chips">
                    <?php foreach (SCORE_OPTIONS as $sc): ?>
                        <label class="chip">
                            <input type="checkbox" name="scores[]" value="<?= e($sc) ?>" <?= in_array($sc, $formScores, true) ? 'checked' : '' ?>>
                            <span><?= e($sc) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <label class="chip chip-all">
                        <input type="checkbox" data-select-all>
                        <span>Выбрать все</span>
                    </label>
                </div>
            </fieldset>
            <div class="btn-row">
                <button class="btn primary">Сохранить критерий</button>
                <?php if (!$editing): ?>
                    <button name="and_next" value="1" class="btn">Сохранить и добавить ещё</button>
                <?php endif; ?>
                <a class="btn ghost" href="<?= $self ?>" <?= !$editing && $criteria ? 'data-toggle="#crit-form"' : '' ?>>Отменить</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if (!$wiz): ?>
    <div class="card threshold-row">
        <div>
            <b>Порог сдачи: <?= (int)$st['pass_threshold'] ?>%</b>
            <div class="muted small">Станция сдана, если набрано не меньше <?= (int)$st['pass_threshold'] ?>% от максимума.</div>
        </div>
        <?php if (!$started): ?><a class="btn small" href="<?= e($thresholdUrl) ?>">Изменить порог</a><?php endif; ?>
    </div>
<?php endif; ?>

<div class="wizard-buttons">
    <div class="btn-row">
        <?php if ($wiz): ?>
            <a class="btn" href="station_new.php?station=<?= $sid . $qs ?>">← Название</a>
            <?php if (is_admin()): ?>
                <button form="station-form" name="next" value="finish" class="btn"
                        title="Сохранить станцию сейчас — критерии можно добавить позже">Пропустить</button>
            <?php endif; ?>
            <?php if ($criteria): ?>
                <button form="station-form" name="next" value="threshold" class="btn primary">Порог сдачи →</button>
            <?php else: ?>
                <button class="btn primary" disabled title="Сначала добавьте хотя бы один критерий">Порог сдачи →</button>
            <?php endif; ?>
        <?php else: ?>
            <a class="btn" href="<?= e($back) ?>">Отменить</a>
            <button form="station-form" class="btn primary"><?= icon('save') ?> Сохранить станцию</button>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
