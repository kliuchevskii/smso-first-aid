<?php
require_once 'functions.php';
require_login();
enter_instructor();   // сотрудник управления здесь работает как инструктор

$id  = (int)($_GET['id'] ?? 0);
$att = get_attestation($pdo, $id);
if (!$att || !is_member($pdo, $id, uid())) {
    flash('Аттестация не найдена или вы к ней не присоединены', 'err');
    redirect('index.php');
}

$mySt = my_stations($pdo, uid(), $id);

// Станции ГИ, которые этот ПИ ещё не взял
$aq = $pdo->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM criteria c WHERE c.station_id = s.id) AS crit_cnt,
           (SELECT COUNT(*) FROM station_judges sj WHERE sj.station_id = s.id) AS judges_cnt
    FROM stations s
    WHERE s.attestation_id = ? AND s.created_by_admin = 1
      AND s.id NOT IN (SELECT station_id FROM station_judges WHERE user_id = ?)
    ORDER BY s.id
");
$aq->execute([$id, uid()]);
$adminList = $aq->fetchAll();
$isDraft = $att['status'] === 'draft';
$oneToOne = (int)$att['one_judge_per_station'] === 1;
$allowOwn = (int)$att['allow_own_stations'] === 1;
if ($oneToOne) {
    // занятые другими станции не показываем
    $adminList = array_values(array_filter($adminList, fn($s) => (int)$s['judges_cnt'] === 0));
}
$started = att_started($pdo, $id);
$canAdd = $isDraft && !$started && !($oneToOne && $mySt);

page_start($att['title'], ['back' => 'index.php']);
?>
<section class="page-head">
    <div>
        <h1><?= e($att['title']) ?></h1>
        <div class="head-meta">
            <span class="badge <?= status_class($att['status']) ?>"><?= e(status_label($att['status'])) ?></span>
            <span class="muted">Код <code><?= e($att['join_code']) ?></code></span>
        </div>
    </div>
</section>

<?php if (!$isDraft): ?>
    <div class="alert warn">Аттестация завершена — внесение результатов закрыто.</div>
<?php endif; ?>

<section>
    <h2>Мои станции</h2>
    <?php if (!$mySt): ?>
        <div class="empty">У вас пока нет станции. Выберите станцию ниже<?= $allowOwn ? ' или создайте свою' : '' ?>.</div>
    <?php else: ?>
        <div class="tiles">
            <?php foreach ($mySt as $s): ?>
                <button type="button" class="tile tile-btn" data-dialog="#my-st-<?= (int)$s['id'] ?>">
                    <div class="tile-top">
                        <b class="tile-title"><?= e($s['name']) ?></b>
                        <span class="badge <?= $s['judge_approved'] ? 'ok' : 'warn' ?>">
                            <?= $s['judge_approved'] ? 'доступ открыт' : 'ждёт подтверждения' ?>
                        </span>
                    </div>
                    <div class="tile-meta">
                        <?= $s['created_by_admin'] ? 'Станция управления' : 'Моя станция' ?> ·
                        порог <?= (int)$s['pass_threshold'] ?>% ·
                        <?= plural((int)$s['crit_cnt'], 'критерий', 'критерия', 'критериев') ?> ·
                        оценено <?= (int)$s['part_cnt'] ?>
                    </div>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($isDraft && !$canAdd): ?>
    <p class="muted small"><?= $started ? 'Приём аттестуемых уже начался — новые станции не добавляются.' : 'В этой аттестации у инструктора может быть только одна станция.' ?></p>
<?php endif; ?>
<?php if ($canAdd): ?>
    <section class="<?= $allowOwn ? 'two-col' : '' ?>">
        <div class="card">
            <h2 class="mt0">Станции управления</h2>
            <p class="muted small">Готовые станции сотрудника управления. После выбора он подтвердит доступ.</p>
            <?php if (!$adminList): ?>
                <p class="muted">Свободных станций нет.</p>
            <?php else: ?>
                <div class="pick-list">
                    <?php foreach ($adminList as $s): ?>
                        <form method="post" action="pick_station.php" class="pick-row">
                            <input type="hidden" name="station" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="attestation" value="<?= $id ?>">
                            <div class="station-link" role="button" tabindex="0" data-dialog="#gi-st-<?= (int)$s['id'] ?>">
                                <b><?= e($s['name']) ?></b>
                                <div class="muted small">
                                    <?= (int)$s['crit_cnt'] ? plural((int)$s['crit_cnt'], 'критерий', 'критерия', 'критериев') : 'критерии зададите вы' ?>
                                    <?= (int)$s['judges_cnt'] ? ' · уже судят: ' . (int)$s['judges_cnt'] : '' ?>
                                </div>
                            </div>
                            <button class="btn small primary">Выбрать</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($allowOwn): ?>
        <div class="card">
            <h2 class="mt0">Своя станция</h2>
            <p class="muted small">Назовите станцию, затем добавьте критерии и порог сдачи. Сотрудник управления подтвердит станцию.</p>
            <form method="post" action="create_station_instructor.php" class="stack">
                <input type="hidden" name="attestation" value="<?= $id ?>">
                <input name="station_name" placeholder="Название станции" required>
                <button class="btn primary">Создать станцию</button>
            </form>
        </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
// Окна с критериями
foreach ($mySt as $s) {
    $sid = (int)$s['id'];
    $canEdit = $isDraft && !$started && (!$s['created_by_admin'] || (int)$att['allow_edit_admin_stations'] === 1);
    $acts = ($canEdit ? '<a class="btn" href="criteria_edit.php?station=' . $sid . '">' . icon('edit') . ' Редактировать</a>' : '')
          . '<a class="btn primary" href="station_instructor.php?id=' . $sid . '">Открыть станцию →</a>';
    station_dialog("my-st-$sid", $s, station_criteria($pdo, $sid), $acts);
}
if ($canAdd) {
    foreach ($adminList as $s) {
        $sid = (int)$s['id'];
        station_dialog("gi-st-$sid", $s, station_criteria($pdo, $sid),
            '<form method="post" action="pick_station.php" class="inline"><input type="hidden" name="station" value="' . $sid . '">'
            . '<input type="hidden" name="attestation" value="' . $id . '"><button class="btn primary">Выбрать станцию</button></form>');
    }
}
page_end();
