<?php
// Порог сдачи станции в процентах (шаг 3 мастера станции или отдельное изменение)
require_once 'functions.php';
require_login();

$sid = (int)($_GET['station'] ?? 0);
station_context($pdo, $sid);
$st  = station_access($pdo, $sid);
if (!$st) {
    flash('Станция не найдена или нет доступа', 'err');
    redirect('index.php');
}
$wiz  = !empty($_GET['wizard']);
$for  = (int)($_GET['for'] ?? 0);
$qs   = station_wiz_qs();
$back = $wiz
    ? "criteria_edit.php?station=$sid$qs"
    : (is_admin() ? "attestation.php?id={$st['attestation_id']}#stations" : "station_instructor.php?id=$sid");

if (!att_editable($st['att_status']) || !$st['can_edit'] || station_locked($pdo, $sid)) {
    flash(station_locked($pdo, $sid) ? STARTED_MSG : 'Порог этой станции сейчас изменить нельзя', 'err');
    redirect(is_admin() ? "attestation.php?id={$st['attestation_id']}" : "station_instructor.php?id=$sid");
}

$criteria = station_criteria($pdo, $sid);
$max = count($criteria);

if (is_post()) {
    $t = (int)($_POST['threshold'] ?? 0);
    if ($t < PASS_MIN || $t > 100) {
        flash('Порог должен быть от ' . PASS_MIN . ' до 100%', 'err');
        redirect($_SERVER['REQUEST_URI']);
    }
    $pdo->prepare("UPDATE stations SET pass_threshold = ? WHERE id = ?")->execute([$t, $sid]);
    if ($wiz) {
        flash(is_admin() && $for ? "Станция создана, порог сдачи $t%. Назначьте инструктора." : "Станция готова. Порог сдачи — $t%.");
        redirect(station_finish_url($st, $for));
    }
    flash("Порог сдачи установлен: $t%");
    redirect(is_admin() ? "attestation.php?id={$st['attestation_id']}#stations" : "station_instructor.php?id=$sid");
}

$cur = (int)$st['pass_threshold'] ?: PASS_DEFAULT;
page_start('Порог сдачи', ['back' => $back, 'width' => 'narrow']);
?>
<?php if ($wiz) wizard_steps(3, $sid); ?>
<div class="card">
    <h1>Установите порог сдачи в процентах</h1>
    <p class="muted">Станция «<?= e($st['name']) ?>». Аттестуемый сдаёт станцию, если наберёт не меньше этого процента от максимума. Минимум — <?= PASS_MIN ?>%.</p>

    <form method="post" class="threshold-form" data-threshold data-max="<?= $max ?>">
        <div class="threshold-input">
            <input type="number" name="threshold" min="<?= PASS_MIN ?>" max="100" step="1" value="<?= $cur ?>" required data-autofocus>
            <span>%</span>
        </div>
        <input type="range" min="<?= PASS_MIN ?>" max="100" step="5" value="<?= $cur ?>" aria-label="Порог" data-threshold-range>
        <div class="chips">
            <?php foreach ([50, 60, 70, 80, 90, 100] as $p): ?>
                <button type="button" class="chip-btn" data-set="<?= $p ?>"><?= $p ?>%</button>
            <?php endforeach; ?>
        </div>
        <?php if ($max): ?>
            <p class="threshold-hint" data-threshold-hint>
                Нужно набрать не меньше <b><?= fmt_score(ceil($max * $cur) / 100) ?></b> из <?= $max ?> баллов.
            </p>
        <?php endif; ?>
        <div class="btn-row form-actions wizard-buttons">
            <a class="btn" href="<?= e($back) ?>"><?= $wiz ? '← Критерии' : 'Отменить' ?></a>
            <button class="btn primary">Установить</button>
        </div>
    </form>
</div>
<?php page_end();
