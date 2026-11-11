<?php
// Мастер создания аттестации: название → станции → настройки → инструкторы → публикация.
// Пока аттестация не опубликована, она хранится как черновик (status = 'prep')
// и не видна инструкторам. По шагам можно переходить в любом порядке.
require_once 'functions.php';
require_role('admin');

const WIZ_STEPS = [1 => 'Название', 2 => 'Станции', 3 => 'Настройки', 4 => 'Инструкторы'];

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$att  = $id ? get_attestation($pdo, $id) : null;
$step = max(1, min(4, (int)($_GET['step'] ?? $_POST['step'] ?? ($att['wizard_step'] ?? 1))));
if ($id && !$att) {
    flash('Аттестация не найдена', 'err');
    redirect('manage.php');
}
if ($att && $att['status'] !== 'prep') redirect("attestation.php?id=$id");
if (!$att) $step = 1;

function new_join_code(PDO $pdo): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $chk = $pdo->prepare("SELECT 1 FROM attestations WHERE join_code = ?");
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $chk->execute([$code]);
    } while ($chk->fetchColumn());
    return $code;
}

/* ======================== Сохранение шага ======================== */
if (is_post()) {
    $nav = (string)($_POST['nav'] ?? 'next');

    if ($nav === 'delete' && $att) {
        $sids = $pdo->prepare("SELECT id FROM stations WHERE attestation_id = ?");
        $sids->execute([$id]);
        foreach ($sids->fetchAll(PDO::FETCH_COLUMN) as $x) {
            $pdo->prepare("DELETE FROM criteria WHERE station_id = ?")->execute([$x]);
            $pdo->prepare("DELETE FROM station_judges WHERE station_id = ?")->execute([$x]);
        }
        $pdo->prepare("DELETE FROM stations WHERE attestation_id = ?")->execute([$id]);
        delete_participant_accounts($pdo, $id);   // временные учётные записи аттестуемых
        $pdo->prepare("DELETE FROM attestation_members WHERE attestation_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM attestations WHERE id = ?")->execute([$id]);
        flash('Черновик удалён', 'warn');
        redirect('manage.php');
    }

    if ($step === 1) {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            flash('Укажите название аттестации', 'err');
            redirect($id ? "attestation_wizard.php?id=$id&step=1" : 'attestation_wizard.php');
        }
        if ($att) {
            $pdo->prepare("UPDATE attestations SET title = ? WHERE id = ?")->execute([$title, $id]);
        } else {
            $pdo->prepare("INSERT INTO attestations (title, join_code, created_by, status, pass_threshold) VALUES (?, ?, ?, 'prep', ?)")
                ->execute([$title, new_join_code($pdo), uid(), PASS_DEFAULT]);
            $id = (int)$pdo->lastInsertId();
        }
    }
    if ($step === 3 && $att) {
        $set = read_settings($_POST);
        save_att_settings($pdo, $id, $set, read_att_threshold($_POST), read_verdict_mode($_POST));
    }
    if ($step === 4 && $att && isset($_POST['users_sent'])) {
        // приглашённые = отмеченные галочками
        $want = array_unique(array_map('intval', (array)($_POST['users'] ?? [])));
        $cur = $pdo->prepare("SELECT user_id FROM attestation_members WHERE attestation_id = ?");
        $cur->execute([$id]);
        foreach (array_map('intval', $cur->fetchAll(PDO::FETCH_COLUMN)) as $u) {
            if (!in_array($u, $want, true)) {
                $pdo->prepare("DELETE FROM attestation_members WHERE attestation_id = ? AND user_id = ?")->execute([$id, $u]);
            }
        }
        foreach ($want as $u) invite_member($pdo, $att, $u);   // черновик: уведомления уйдут при публикации
    }

    if ($nav === 'draft') {
        $pdo->prepare("UPDATE attestations SET wizard_step = ? WHERE id = ?")->execute([$step, $id]);
        flash('Черновик сохранён. Его можно продолжить из списка аттестаций — откроется этот же шаг.');
        redirect('manage.php');
    }
    if ($nav === 'publish') {
        $pdo->prepare("UPDATE attestations SET status = 'draft' WHERE id = ?")->execute([$id]);
        $a = get_attestation($pdo, $id);
        // приглашённые получат всплывающее уведомление
        $mq = $pdo->prepare("SELECT user_id FROM attestation_members WHERE attestation_id = ? AND status = 'invited'");
        $mq->execute([$id]);
        foreach ($mq->fetchAll(PDO::FETCH_COLUMN) as $u) notify_invite($pdo, (int)$u, $a);
        flash("Аттестация «{$a['title']}» опубликована. Код для инструкторов: {$a['join_code']}");
        redirect("attestation.php?id=$id");
    }
    $to = $step + 1;
    if ($nav === 'prev') $to = $step - 1;
    if (str_starts_with($nav, 'goto')) $to = (int)substr($nav, 4);
    $to = max(1, min(4, $to));
    $pdo->prepare("UPDATE attestations SET wizard_step = ? WHERE id = ?")->execute([$to, $id]);
    redirect("attestation_wizard.php?id=$id&step=$to");
}

// запоминаем шаг — черновик потом откроется на нём
if ($att && (int)$att['wizard_step'] !== $step) {
    $pdo->prepare("UPDATE attestations SET wizard_step = ? WHERE id = ?")->execute([$step, $id]);
}

/* ======================== Данные ======================== */
$stations = []; $criteria = []; $members = [];
if ($att) {
    $sq = $pdo->prepare("SELECT * FROM stations WHERE attestation_id = ? ORDER BY id");
    $sq->execute([$id]);
    $stations = $sq->fetchAll();
    foreach ($stations as $s) $criteria[(int)$s['id']] = station_criteria($pdo, (int)$s['id']);
    $mq = $pdo->prepare("SELECT user_id FROM attestation_members WHERE attestation_id = ?");
    $mq->execute([$id]);
    $members = array_map('intval', $mq->fetchAll(PDO::FETCH_COLUMN));
}

page_start($att ? 'Черновик: ' . $att['title'] : 'Новая аттестация', ['back' => 'manage.php', 'width' => 'narrow']);
?>
<form method="post" id="wiz" class="att-wizard">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="step" value="<?= $step ?>">

    <ol class="steps steps-wide">
        <?php foreach (WIZ_STEPS as $n => $label):
            $cls = $n === $step ? 'active' : ($att && $n < $step ? 'done' : ''); ?>
            <li class="<?= $cls ?>">
                <?php if ($n !== $step): ?>
                    <button class="step-in" type="submit" name="nav" value="goto<?= $n ?>" <?= $att ? 'formnovalidate' : '' ?>><span><?= $n ?></span><?= e($label) ?></button>
                <?php else: ?>
                    <span class="step-in"><span><?= $n ?></span><?= e($label) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <div class="card">
    <?php if ($step === 1): ?>
        <h1>Новая аттестация</h1>
        <label>Название аттестации
            <input name="title" value="<?= e($att['title'] ?? '') ?>" placeholder="Например: Аттестация ПП, ноябрь 2026" required data-autofocus>
        </label>
        <p class="muted small">Пока аттестация не опубликована, она хранится как черновик и не видна инструкторам.</p>

    <?php elseif ($step === 2): ?>
        <h1>Станции</h1>
        <p class="muted small">Создайте станции заранее или пропустите шаг — станции можно добавить и после публикации, а инструкторы смогут предложить свои (если это разрешено в настройках).</p>
        <?php if (!$stations): ?>
            <div class="empty small-empty">Станций пока нет.</div>
        <?php else: ?>
            <div class="pick-list">
                <?php foreach ($stations as $s): $sid = (int)$s['id']; $cnt = count($criteria[$sid]); ?>
                    <div class="pick-row">
                        <button type="button" class="station-link" data-dialog="#st-<?= $sid ?>">
                            <b><?= e($s['name']) ?></b>
                            <span class="muted small"><?= $cnt ? plural($cnt, 'критерий', 'критерия', 'критериев') : 'без критериев' ?> · порог <?= (int)$s['pass_threshold'] ?>%</span>
                        </button>
                        <a class="icon-btn" href="station_new.php?station=<?= $sid ?>&wizard=1&ret=wiz" title="Изменить"><?= icon('edit') ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p><a class="btn" href="station_new.php?att=<?= $id ?>&wizard=1&ret=wiz"><?= icon('plus') ?> Добавить станцию</a></p>

    <?php elseif ($step === 3): ?>
        <h1>Настройки</h1>
        <div class="stack">
            <?php $stc = $pdo->prepare("SELECT COUNT(*) FROM stations s WHERE s.attestation_id = ? AND EXISTS (SELECT 1 FROM criteria c WHERE c.station_id = s.id)");
                  $stc->execute([$id]);
                  att_threshold_field((int)$att['pass_threshold'], false, (int)$stc->fetchColumn(), $att['verdict_mode'] ?? 'avg'); ?>
            <?php settings_switches($att); ?>
        </div>

    <?php else: ?>
        <h1>Инструкторы</h1>
        <p class="muted small">Отметьте, кого пригласить. После публикации они получат уведомление и смогут принять или отклонить приглашение. Остальные присоединятся по коду или QR.</p>
        <input type="hidden" name="users_sent" value="1">
        <?php
        $people = invitable_people($pdo);
        invite_checklist($people, $members);
        ?>
    <?php endif; ?>
    </div>

    <div class="wizard-buttons wizard-bar">
        <div class="btn-row">
            <?php if ($att): ?>
                <button name="nav" value="delete" class="btn danger" formnovalidate data-confirm="Удалить черновик «<?= e($att['title']) ?>»?"><?= icon('trash') ?><span class="hide-sm"> Удалить</span></button>
            <?php endif; ?>
            <button name="nav" value="draft" class="btn"><?= icon('save') ?> Черновик</button>
            <?php if ($step > 1): ?>
                <button name="nav" value="prev" class="btn" formnovalidate>← Назад</button>
            <?php endif; ?>
            <?php if ($step < 4): ?>
                <button name="nav" value="next" class="btn <?= $step === 2 && !$stations ? '' : 'primary' ?>"><?= $step === 2 && !$stations ? 'Пропустить' : 'Далее' ?> →</button>
            <?php else: ?>
                <button name="nav" value="publish" class="btn primary"><?= icon('send') ?> Опубликовать</button>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php foreach ($stations as $s):
    station_dialog('st-' . $s['id'], $s, $criteria[(int)$s['id']],
        '<a class="btn primary" href="station_new.php?station=' . (int)$s['id'] . '&wizard=1&ret=wiz">' . icon('edit') . ' Редактировать</a>');
endforeach; ?>
<?php page_end();
