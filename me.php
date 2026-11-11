<?php
// Личная страница аттестуемого: его станции и (в зависимости от настроек) баллы
require_once 'functions.php';
require_login();
if (!is_participant()) redirect('index.php');

$me = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$me->execute([uid()]);
$me = $me->fetch();
$att = $me['attestation_id'] ? get_attestation($pdo, (int)$me['attestation_id']) : null;
$pid = (int)$me['participant_id'];
$num = $att ? participant_num($pdo, (int)$att['id'], $pid) : null;

// смена временного пароля (его выдаёт сотрудник управления)
if (is_post() && ($_POST['action'] ?? '') === 'password') {
    $p1 = (string)($_POST['password'] ?? '');
    if (mb_strlen($p1) < 6) {
        flash('Пароль должен быть не короче 6 символов', 'err');
    } elseif ($p1 !== (string)($_POST['password2'] ?? '')) {
        flash('Пароли не совпадают', 'err');
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, temp_password = NULL WHERE id = ?")
            ->execute([password_hash($p1, PASSWORD_DEFAULT), uid()]);
        flash('Пароль сохранён');
    }
    redirect('me.php');
}

$stations = [];
$total = 0.0; $maxTotal = 0; $doneCnt = 0;
if ($att) {
    $q = $pdo->prepare("SELECT s.id, s.name, s.pass_threshold,
                               (SELECT COUNT(*) FROM criteria c WHERE c.station_id = s.id) AS max_score
                        FROM stations s WHERE s.attestation_id = ? ORDER BY s.id");
    $q->execute([$att['id']]);
    $r = $pdo->prepare("SELECT station_id, SUM(score) AS total, COUNT(*) AS cnt FROM results WHERE participant_id = ? GROUP BY station_id");
    $r->execute([$pid]);
    $res = [];
    foreach ($r->fetchAll() as $x) $res[(int)$x['station_id']] = $x;
    foreach ($q->fetchAll() as $s) {
        if ((int)$s['max_score'] === 0 && !isset($res[(int)$s['id']])) continue;   // пустая станция-заготовка
        $x = $res[(int)$s['id']] ?? null;
        $s['done'] = $x !== null;
        $s['sum'] = $x ? (float)$x['total'] : 0.0;
        $s['cnt'] = $x ? (int)$x['cnt'] : 0;
        $s['passed'] = $x ? station_passed($s['sum'], $s['cnt'], (int)$s['max_score'], (int)$s['pass_threshold']) : null;
        $stations[] = $s;
        $total += $s['sum'];
        $maxTotal += (int)$s['max_score'];
        if ($s['done']) $doneCnt++;
    }
}
$confirmed = $att && $att['status'] === 'confirmed';
$reveal = $att && ((int)$att['show_scores'] === 1 || $confirmed);
$verdict = null;
if ($confirmed) {
    $m = attestation_matrix($pdo, (int)$att['id']);
    $verdict = $m['rows'][$pid] ?? null;
}

page_start('Моя аттестация', ['section' => 'me', 'width' => 'narrow']);
?>
<?php if ((int)$me['must_change_password']): ?>
    <div class="card">
        <h2 class="mt0">Задайте свой пароль</h2>
        <p class="muted small">Вы вошли с временным паролем. Придумайте новый, чтобы потом снова открыть результаты.</p>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="password">
            <div class="grid-2">
                <label>Новый пароль <input type="password" name="password" minlength="6" autocomplete="new-password" required></label>
                <label>Повторите <input type="password" name="password2" minlength="6" autocomplete="new-password" required></label>
            </div>
            <div><button class="btn primary"><?= icon('key') ?> Сохранить пароль</button></div>
        </form>
    </div>
<?php endif; ?>

<div class="card profile-head me-head">
    <span class="avatar avatar-lg"><?= e(initials($me['full_name'])) ?></span>
    <div class="ph-main">
        <h1><?= e($me['full_name']) ?></h1>
        <p class="meta"><?= $att ? 'Аттестация «' . e($att['title']) . '»' : 'Аттестация удалена' ?></p>
        <?php if ($att): ?>
            <div class="ph-badges"><span class="badge <?= status_class($att['status']) ?>"><?= e(status_label($att['status'])) ?></span></div>
        <?php endif; ?>
    </div>
    <div class="ph-stats">
        <div class="ph-stat me-number" title="Назовите этот номер инструктору — так он быстрее найдёт вас в списке"><b><?= $num ? '№' . $num : '—' ?></b><span>ваш номер</span></div>
        <div class="ph-stat"><b><?= $doneCnt ?>/<?= count($stations) ?></b><span>станций пройдено</span></div>
    </div>
</div>

<?php if ($att): ?>
    <?php if ($confirmed && $verdict): ?>
        <div class="card me-verdict <?= $verdict['verdict'] === 'pass' ? 'is-pass' : 'is-fail' ?>">
            <div>
                <div class="muted small">Итог аттестации · результаты подтверждены</div>
                <b class="me-verdict-title"><?= $verdict['verdict'] === 'pass' ? 'Аттестация сдана' : 'Аттестация не сдана' ?></b>
            </div>
            <div class="me-verdict-score"><b>Станций сдано: <?= (int)$verdict['st_passed'] ?> из <?= (int)$m['st_count'] ?></b><span>результат <?= fmt_pct($verdict['pct']) ?> (среднее по станциям) · итог: <?= e(verdict_rule($m)) ?></span></div>
        </div>
    <?php elseif ($confirmed): ?>
        <div class="alert info">Результаты аттестации подтверждены, но ваших оценок в ней нет.</div>
    <?php elseif ($reveal): ?>
        <div class="alert info">Баллы видны сразу. Итог аттестации появится после того, как сотрудник управления подтвердит результаты.</div>
    <?php else: ?>
        <div class="alert info">Здесь отмечаются станции, которые вы прошли. Баллы и итог появятся после того, как сотрудник управления подтвердит результаты аттестации.</div>
    <?php endif; ?>

    <h2>Станции</h2>
    <?php if (!$stations): ?>
        <div class="empty">Станции ещё не готовы.</div>
    <?php else: ?>
        <div class="me-stations">
            <?php foreach ($stations as $i => $s): ?>
                <div class="me-station <?= $s['done'] ? 'is-done' : '' ?>">
                    <span class="me-st-num"><?= $s['done'] ? icon('check') : $i + 1 ?></span>
                    <div class="me-st-name"><b><?= e($s['name']) ?></b>
                        <span class="muted small"><?= $s['done'] ? 'пройдена' : 'ещё не пройдена' ?></span></div>
                    <?php if ($s['done'] && $reveal): ?>
                        <div class="me-st-score">
                            <b><?= fmt_pct(pct($s['sum'], (int)$s['max_score'])) ?></b><span class="muted small"><?= fmt_score($s['sum']) ?>/<?= (int)$s['max_score'] ?></span>
                            <?php if ($s['passed'] === true): ?><span class="badge ok">сдана</span>
                            <?php elseif ($s['passed'] === false): ?><span class="badge err">не сдана</span>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($s['done']): ?>
                        <span class="badge ok">пройдена</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($reveal && !$confirmed && $maxTotal): ?>
            <?php $passedNow = count(array_filter($stations, fn($s) => $s['passed'] === true));
                  $avgNow = $stations ? array_sum(array_map(fn($s) => pct($s['sum'], (int)$s['max_score']), $stations)) / count($stations) : 0; ?>
            <p class="muted small">Сейчас сдано станций: <?= $passedNow ?> из <?= count($stations) ?>, результат <?= fmt_pct(round($avgNow, 1)) ?> (среднее по станциям). Для сдачи аттестации <?= ($att['verdict_mode'] ?? 'avg') === 'stations' ? e(needed_text(stations_needed(count($stations), (int)$att['pass_threshold']), count($stations))) : 'средний % станций должен быть не ниже ' . (int)$att['pass_threshold'] . '%' ?>.</p>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<div class="btn-row me-actions">
    <a class="btn" href="me.php"><?= icon('swap') ?> Обновить</a>
    <a class="btn danger-outline" href="logout.php"><?= icon('logout') ?> Выйти</a>
</div>
<?php page_end();
