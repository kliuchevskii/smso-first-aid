<?php
// Мастер станции — шаг 1: название. ?att=ID — новая станция ГИ, ?station=ID — вернуться к шагу 1.
require_once 'functions.php';
require_login();

$sid = (int)($_GET['station'] ?? $_POST['station'] ?? 0);
$for = (int)($_GET['for'] ?? $_POST['for'] ?? 0);
$ret = preg_replace('/[^a-z]/', '', (string)($_GET['ret'] ?? $_POST['ret'] ?? ''));
$st  = null;

if ($sid) {
    station_context($pdo, $sid);
    $st = station_access($pdo, $sid);
    if (!$st || !$st['can_edit']) {
        flash('Станция не найдена или нет доступа', 'err');
        redirect('index.php');
    }
    $attId = (int)$st['attestation_id'];
    $att = get_attestation($pdo, $attId);
} else {
    require_role('admin');
    $attId = (int)($_GET['att'] ?? $_POST['att'] ?? 0);
    $att = get_attestation($pdo, $attId);
}
if (!$att) {
    flash('Аттестация не найдена', 'err');
    redirect('index.php');
}
if (is_admin()) {
    $back = $ret === 'wiz' ? "attestation_wizard.php?id=$attId&step=2" : "attestation.php?id=$attId#" . ($for ? 'instructors' : 'stations');
} else {
    $back = $sid ? "station_instructor.php?id=$sid" : "attestation_instructor.php?id=$attId";
}
if (!att_editable($att['status'])) {
    flash('Аттестация завершена — станции не меняются', 'err');
    redirect($back);
}
// после начала приёма новую станцию может добавить только сотрудник управления
$lateAdd = !$sid && att_started($pdo, $attId);
if ($lateAdd && !is_admin()) {
    flash(STARTED_MSG, 'err');
    redirect($back);
}
$qs = "&wizard=1&for=$for" . ($ret ? "&ret=$ret" : '');

$forName = null;
if ($for) {
    $u = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $u->execute([$for]);
    $forName = $u->fetchColumn() ?: null;
}

if (is_post()) {
    $name = trim($_POST['station_name'] ?? '');
    if ($name === '') {
        flash('Укажите название станции', 'err');
        redirect($sid ? "station_new.php?station=$sid$qs" : "station_new.php?att=$attId$qs");
    }
    if ($sid) {
        if ($st['can_rename']) $pdo->prepare("UPDATE stations SET name = ? WHERE id = ?")->execute([$name, $sid]);
    } else {
        $pdo->prepare("INSERT INTO stations (attestation_id, name, created_by, created_by_admin, approved, pass_threshold, added_late) VALUES (?, ?, ?, 1, 0, ?, ?)")
            ->execute([$attId, $name, uid(), PASS_DEFAULT, $lateAdd ? 1 : 0]);
        $sid = (int)$pdo->lastInsertId();
    }
    if ((int)($_POST['goto'] ?? 0) === 3) redirect("threshold.php?station=$sid$qs");
    redirect("criteria_edit.php?station=$sid$qs" . (station_criteria($pdo, $sid) ? '' : '&add=1'));
}

page_start($sid ? 'Станция' : 'Новая станция', ['back' => $back, 'width' => 'narrow']);
?>
<?php wizard_steps(1, $sid); ?>
<div class="card">
    <h1><?= $sid ? 'Название станции' : 'Новая станция' ?></h1>
    <p class="muted"><?= e($att['title']) ?><?= $forName ? ' · для инструктора ' . e($forName) : '' ?></p>
    <form method="post" class="stack" id="station-name-form">
        <input type="hidden" name="att" value="<?= $attId ?>">
        <input type="hidden" name="station" value="<?= $sid ?>">
        <input type="hidden" name="for" value="<?= $for ?>">
        <input type="hidden" name="ret" value="<?= e($ret) ?>">
        <label>Название станции
            <input name="station_name" value="<?= e($st['name'] ?? '') ?>" placeholder="Например: СЛР взрослого" required data-autofocus
                <?= $st && !$st['can_rename'] ? 'readonly' : '' ?>>
        </label>
        <?php if ($st && !$st['can_rename']): ?><p class="muted small">Название задано сотрудником управления.</p><?php endif; ?>
        <div class="btn-row form-actions wizard-buttons">
            <a class="btn" href="<?= e($back) ?>">Отменить</a>
            <button class="btn primary">Критерии →</button>
        </div>
    </form>
</div>
<?php page_end();
