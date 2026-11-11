<?php
require_once 'functions.php';
require_role('admin');

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$att = get_attestation($pdo, $id);
if (!$att) {
    flash('Аттестация не найдена', 'err');
    redirect('manage.php');
}
if ($att['status'] === 'prep') redirect("attestation_wizard.php?id=$id");
$self = "attestation.php?id=$id";

/** станция принадлежит этой аттестации? */
$stationInAtt = function (int $sid) use ($pdo, $id): ?array {
    $q = $pdo->prepare("SELECT * FROM stations WHERE id = ? AND attestation_id = ?");
    $q->execute([$sid, $id]);
    return $q->fetch() ?: null;
};

/* ======================== Действия ======================== */
if (is_post()) {
    $action = $_POST['action'] ?? '';
    $sid = (int)($_POST['station'] ?? 0);
    $u   = (int)($_POST['user'] ?? 0);
    $tab = 'instructors';

    switch ($action) {
        case 'finish':
            $pdo->prepare("UPDATE attestations SET status = 'finished' WHERE id = ?")->execute([$id]);
            flash('Аттестация завершена. Внесение результатов закрыто.');
            $tab = 'results';
            break;

        case 'confirm':
            $pdo->prepare("UPDATE attestations SET status = 'confirmed', confirmed_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $id]);
            flash('Результаты подтверждены.');
            $tab = 'results';
            break;

        case 'reopen':
            $pdo->prepare("UPDATE attestations SET status = 'draft', confirmed_at = NULL WHERE id = ?")->execute([$id]);
            flash('Аттестация снова открыта для внесения результатов.');
            break;

        case 'approve_judge':
            if ($stationInAtt($sid)) {
                $pdo->prepare("UPDATE station_judges SET approved = 1 WHERE station_id = ? AND user_id = ?")->execute([$sid, $u]);
                $pdo->prepare("UPDATE stations SET approved = 1 WHERE id = ?")->execute([$sid]);
                flash('Станция подтверждена, инструктор получил доступ');
            }
            break;

        case 'revoke_judge':
            if ($stationInAtt($sid)) {
                $pdo->prepare("UPDATE station_judges SET approved = 0 WHERE station_id = ? AND user_id = ?")->execute([$sid, $u]);
                flash('Доступ к станции отозван', 'warn');
            }
            break;

        case 'remove_member':
            // исключить инструктора из аттестации (снимается и со всех её станций); оценки сохраняются
            $pdo->prepare("DELETE FROM station_judges WHERE user_id = ? AND station_id IN (SELECT id FROM stations WHERE attestation_id = ?)")->execute([$u, $id]);
            $pdo->prepare("DELETE FROM attestation_members WHERE attestation_id = ? AND user_id = ?")->execute([$id, $u]);
            notify($pdo, $u, "Вас исключили из аттестации «{$att['title']}»", null, 'info');
            flash('Инструктор исключён из аттестации', 'warn');
            break;

        case 'add_participant':
            $tab = 'participants';
            try {
                $pid = find_or_create_participant($pdo, (string)($_POST['last_name'] ?? ''), (string)($_POST['first_name'] ?? ''));
                if (in_roster($pdo, $id, $pid)) {
                    flash('Этот аттестуемый уже в списке', 'warn');
                } else {
                    roster_add($pdo, $id, $pid, uid());
                    flash('Аттестуемый добавлен');
                }
            } catch (InvalidArgumentException $e) {
                flash($e->getMessage(), 'err');
            }
            break;

        case 'remove_participant':
            $tab = 'participants';
            $pid = (int)($_POST['participant'] ?? 0);
            $pdo->prepare("DELETE FROM attestation_participants WHERE attestation_id = ? AND participant_id = ?")->execute([$id, $pid]);
            $pdo->prepare("DELETE FROM results WHERE participant_id = ? AND station_id IN (SELECT id FROM stations WHERE attestation_id = ?)")->execute([$pid, $id]);
            // зарегистрировался сам — удаляем и его временную учётную запись
            $acc = $pdo->prepare("SELECT id FROM users WHERE role = 'participant' AND participant_id = ? AND attestation_id = ?");
            $acc->execute([$pid, $id]);
            if ($accId = (int)$acc->fetchColumn()) {
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$accId]);
                $pdo->prepare("DELETE FROM participants WHERE id = ?")->execute([$pid]);
            }
            flash('Аттестуемый удалён из аттестации вместе с его результатами' . (!empty($accId) ? ' и учётной записью' : ''), 'warn');
            break;

        case 'participant_password':
            // временный пароль для аттестуемого, который зарегистрировался сам
            $tab = 'participants';
            $pid = (int)($_POST['participant'] ?? 0);
            $acc = $pdo->prepare("SELECT u.id, u.full_name FROM users u WHERE u.role = 'participant' AND u.participant_id = ? AND u.attestation_id = ?");
            $acc->execute([$pid, $id]);
            if ($a = $acc->fetch()) {
                $tmp = (string)random_int(100000, 999999);
                $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, temp_password = ? WHERE id = ?")
                    ->execute([password_hash($tmp, PASSWORD_DEFAULT), $tmp, $a['id']]);
                flash("Временный пароль для {$a['full_name']}: $tmp. Аттестуемый входит с ним через «Вход» на странице регистрации и задаёт свой.", 'warn');
            }
            break;

        case 'cancel_invite':
            $pdo->prepare("DELETE FROM attestation_members WHERE attestation_id = ? AND user_id = ? AND status = 'invited'")->execute([$id, $u]);
            flash('Приглашение отменено', 'warn');
            break;

        case 'remove_judge':
            if ($stationInAtt($sid)) {
                $pdo->prepare("DELETE FROM station_judges WHERE station_id = ? AND user_id = ?")->execute([$sid, $u]);
                flash('Инструктор снят со станции', 'warn');
            }
            $tab = $_POST['tab'] ?? 'instructors';
            break;

        case 'create_station':
            $name = trim($_POST['station_name'] ?? '');
            if ($name !== '') {
                $pdo->prepare("INSERT INTO stations (attestation_id, name, created_by, created_by_admin, approved) VALUES (?, ?, ?, 1, 0)")
                    ->execute([$id, $name, uid()]);
                flash("Станция «{$name}» создана");
            }
            $tab = 'stations';
            break;

        case 'assign':
            $tab = ($_POST['tab'] ?? '') === 'instructors' ? 'instructors' : 'stations';
            if (!$stationInAtt($sid)) break;
            if (!is_member($pdo, $id, $u)) {
                flash('Инструктор не присоединён к аттестации', 'err');
                break;
            }
            $ex = $pdo->prepare("SELECT 1 FROM station_judges WHERE station_id = ? AND user_id = ?");
            $ex->execute([$sid, $u]);
            if ($ex->fetchColumn()) {
                $pdo->prepare("UPDATE station_judges SET approved = 1 WHERE station_id = ? AND user_id = ?")->execute([$sid, $u]);
            } else {
                $pdo->prepare("INSERT INTO station_judges (station_id, user_id, approved) VALUES (?, ?, 1)")->execute([$sid, $u]);
            }
            $pdo->prepare("UPDATE stations SET approved = 1 WHERE id = ?")->execute([$sid]);
            $note = '';
            if ((int)$att['one_judge_per_station']) {
                // 1:1 — снимаем других инструкторов со станции и этого инструктора с других станций
                $d1 = $pdo->prepare("DELETE FROM station_judges WHERE station_id = ? AND user_id <> ?");
                $d1->execute([$sid, $u]);
                $d2 = $pdo->prepare("DELETE FROM station_judges WHERE user_id = ? AND station_id <> ?
                                     AND station_id IN (SELECT id FROM stations WHERE attestation_id = ?)");
                $d2->execute([$u, $sid, $id]);
                if ($d1->rowCount() + $d2->rowCount() > 0) $note = ' Прежние назначения сняты (режим «один инструктор — одна станция»).';
            }
            flash('Инструктор назначен на станцию.' . $note);
            break;

        case 'delete_attestation':
            if ($att['status'] === 'draft') {
                flash('Сначала завершите аттестацию', 'err');
                break;
            }
            $sids = $pdo->prepare("SELECT id FROM stations WHERE attestation_id = ?");
            $sids->execute([$id]);
            foreach ($sids->fetchAll(PDO::FETCH_COLUMN) as $x) {
                foreach (['results', 'criteria', 'station_judges'] as $t) {
                    $pdo->prepare("DELETE FROM $t WHERE station_id = ?")->execute([$x]);
                }
            }
            $pdo->prepare("DELETE FROM stations WHERE attestation_id = ?")->execute([$id]);
            delete_participant_accounts($pdo, $id);   // временные учётные записи аттестуемых
            $pdo->prepare("DELETE FROM attestation_members WHERE attestation_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM attestations WHERE id = ?")->execute([$id]);
            flash("Аттестация «{$att['title']}» удалена", 'warn');
            redirect('manage.php');

        case 'save_settings':
            $set = read_settings($_POST);
            $frozen = att_started($pdo, $id);   // порог и способ подсчёта итога заморожены после начала приёма
            $thr = $frozen ? (int)$att['pass_threshold'] : read_att_threshold($_POST);
            save_att_settings($pdo, $id, $set, $thr, $frozen ? null : read_verdict_mode($_POST));
            $msg = 'Настройки сохранены.';
            if ($set['one_judge_per_station']) {
                $c = $pdo->prepare("
                    SELECT COUNT(*) FROM (
                        SELECT sj.station_id FROM station_judges sj JOIN stations s ON s.id = sj.station_id
                        WHERE s.attestation_id = ? GROUP BY sj.station_id HAVING COUNT(*) > 1
                        UNION ALL
                        SELECT sj.user_id FROM station_judges sj JOIN stations s ON s.id = sj.station_id
                        WHERE s.attestation_id = ? GROUP BY sj.user_id HAVING COUNT(*) > 1
                    ) x");
                $c->execute([$id, $id]);
                if ((int)$c->fetchColumn() > 0) {
                    $msg .= ' Внимание: уже есть станции с несколькими инструкторами или инструкторы с несколькими станциями — проверьте вкладку «Инструкторы».';
                    flash($msg, 'warn');
                    $tab = 'instructors';
                    break;
                }
            }
            flash($msg);
            $tab = 'instructors';
            break;

        case 'delete_station':
            $tab = $_POST['tab'] ?? 'stations';
            if (!$stationInAtt($sid)) break;
            // удалить можно любую станцию (в том числе созданную инструктором), пока она не заблокирована:
            // до начала приёма — любую; после — только добавленную позже и без оценок
            if (station_locked($pdo, $sid)) {
                flash(STARTED_MSG, 'err');
                break;
            }
            $pdo->prepare("DELETE FROM criteria WHERE station_id = ?")->execute([$sid]);
            $pdo->prepare("DELETE FROM station_judges WHERE station_id = ?")->execute([$sid]);
            $pdo->prepare("DELETE FROM stations WHERE id = ?")->execute([$sid]);
            flash('Станция удалена', 'warn');
            break;
    }
    $tab = in_array($tab, ['instructors', 'results', 'stations', 'participants', 'settings'], true) ? $tab : 'instructors';
    redirect("$self#$tab");
}

/* ======================== Данные ======================== */
$matrix = attestation_matrix($pdo, $id);

// Excel с формулами (лист «Итоги» и лист «Критерии»)
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/attestation_xlsx.php';
    attestation_xlsx($pdo, $att, $matrix);
}

// CSV — простая таблица значений (старая ссылка продолжает работать)
if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attestation_' . $id . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['Аттестуемый'];
    foreach ($matrix['stations'] as $s) $head[] = $s['name'] . ', % (из ' . $s['max'] . ' б., порог ' . $s['threshold'] . '%)';
    $head[] = 'Сдано станций (из ' . $matrix['st_count'] . ')';
    $head[] = 'Результат, % (среднее по станциям)';
    $head[] = 'Баллы (из ' . $matrix['max_total'] . ')';
    $head[] = 'Итог (' . verdict_rule($matrix) . ')';
    fputcsv($out, $head, ';', '"', '');
    foreach ($matrix['rows'] as $row) {
        $line = [$row['name']];
        foreach ($matrix['stations'] as $sid => $s) {
            $line[] = isset($row['cells'][$sid]) ? str_replace('.', ',', (string)pct($row['cells'][$sid]['sum'], $s['max'])) : '';
        }
        $line[] = $row['st_passed'];
        $line[] = str_replace('.', ',', (string)$row['pct']);
        $line[] = str_replace('.', ',', fmt_score($row['total']));
        $line[] = $row['verdict'] === 'pass' ? 'Сдал' : ($row['verdict'] === 'fail' || $att['status'] !== 'draft' ? 'Не сдал' : 'В процессе');
        fputcsv($out, $line, ';', '"', '');
    }
    exit;
}

$mq = $pdo->prepare("
    SELECT u.id, u.full_name, u.username, m.status
    FROM users u JOIN attestation_members m ON m.user_id = u.id
    WHERE m.attestation_id = ?
    ORDER BY u.full_name
");
$mq->execute([$id]);
$allMembers = $mq->fetchAll();
$members = array_values(array_filter($allMembers, fn($m) => ($m['status'] ?: 'active') === 'active'));
$invited = array_values(array_filter($allMembers, fn($m) => $m['status'] === 'invited'));

$sq = $pdo->prepare("SELECT * FROM stations WHERE attestation_id = ? ORDER BY id");
$sq->execute([$id]);
$stations = [];
foreach ($sq->fetchAll() as $s) $stations[(int)$s['id']] = $s;

$criteria = [];
$cq = $pdo->prepare("SELECT c.* FROM criteria c JOIN stations s ON s.id = c.station_id WHERE s.attestation_id = ? ORDER BY c.id");
$cq->execute([$id]);
foreach ($cq->fetchAll() as $c) $criteria[(int)$c['station_id']][] = $c;

$resCnt = [];
$rq = $pdo->prepare("SELECT r.station_id, COUNT(DISTINCT r.participant_id) AS n FROM results r JOIN stations s ON s.id = r.station_id WHERE s.attestation_id = ? GROUP BY r.station_id");
$rq->execute([$id]);
foreach ($rq->fetchAll() as $r) $resCnt[(int)$r['station_id']] = (int)$r['n'];

$judgesByUser = [];     // user_id => [ [station, approved], ... ]
$judgesByStation = [];  // station_id => [ [user_id, name, approved] ]
$jq = $pdo->prepare("
    SELECT sj.station_id, sj.user_id, sj.approved, u.full_name
    FROM station_judges sj
    JOIN stations s ON s.id = sj.station_id
    JOIN users u ON u.id = sj.user_id
    WHERE s.attestation_id = ?
    ORDER BY sj.station_id
");
$jq->execute([$id]);
$pending = 0;
foreach ($jq->fetchAll() as $j) {
    $judgesByUser[(int)$j['user_id']][] = ['sid' => (int)$j['station_id'], 'approved' => (int)$j['approved']];
    $judgesByStation[(int)$j['station_id']][] = ['uid' => (int)$j['user_id'], 'name' => $j['full_name'], 'approved' => (int)$j['approved']];
    if (!(int)$j['approved']) $pending++;
}

// Участники: внесённые сотрудником управления + оценённые инструкторами
$partList = [];
foreach (att_roster($pdo, $id) as $p) {
    $partList[(int)$p['id']] = ['name' => participant_name($p), 'roster' => true, 'stations' => 0,
                                'self' => !empty($p['user_id']), 'birth' => $p['birth_date'] ?? null, 'num' => $p['num'] ?? null];
}
$pc = $pdo->prepare("SELECT p.id, p.last_name, p.first_name, COUNT(DISTINCT r.station_id) AS st
                     FROM results r JOIN participants p ON p.id = r.participant_id JOIN stations s ON s.id = r.station_id
                     WHERE s.attestation_id = ? GROUP BY p.id, p.last_name, p.first_name");
$pc->execute([$id]);
foreach ($pc->fetchAll() as $p) {
    $partList[(int)$p['id']] = ($partList[(int)$p['id']] ?? ['name' => $p['last_name'] . ' ' . $p['first_name'], 'roster' => false])
        + ['stations' => 0];
    $partList[(int)$p['id']]['stations'] = (int)$p['st'];
}
uasort($partList, fn($a, $b) => strcmp($a['name'], $b['name']));
$byAdmin = roster_mode($att);
$selfReg = (int)$att['self_register'] === 1;

$adminStations = array_filter($stations, fn($s) => (int)$s['created_by_admin'] === 1);
$isDraft = $att['status'] === 'draft';
$started = att_started($pdo, $id);
$oneToOne = (int)$att['one_judge_per_station'] === 1;
$assignFor = (int)($_GET['assign'] ?? 0);   // после создания станции — открыть назначение этому инструктору
$finished = !$isDraft;
$verdictCnt = ['pass' => 0, 'fail' => 0, 'pending' => 0];
foreach ($matrix['rows'] as $r) $verdictCnt[$r['verdict']]++;

page_start($att['title'], ['back' => 'manage.php']);
?>
<section class="page-head page-head-top">
    <div class="head-main">
        <h1 class="title-row"><?= e($att['title']) ?>
            <button type="button" class="icon-btn gear" data-dialog="#settings-dialog" title="Настройки аттестации" aria-label="Настройки аттестации"><?= icon('gear') ?></button></h1>
        <div class="head-meta">
            <span class="badge <?= status_class($att['status']) ?>"><?= e(status_label($att['status'])) ?></span>
            <?php if ($att['status'] === 'confirmed' && $att['confirmed_at']): ?>
                <span class="muted">подтверждены <?= e(date('d.m.Y H:i', strtotime($att['confirmed_at']))) ?></span>
            <?php endif; ?>
        </div>
        <div class="att-actions action-bar">
            <form method="post" class="btn-row">
                <input type="hidden" name="id" value="<?= $id ?>">
                <?php if ($att['status'] === 'draft'): ?>
                    <button name="action" value="finish" class="btn" data-confirm="Завершить аттестацию? Инструкторы больше не смогут вносить результаты.">Завершить аттестацию</button>
                    <button name="action" value="confirm" class="btn primary" data-confirm="Завершить аттестацию и подтвердить результаты?">Подтвердить результаты</button>
                <?php elseif ($att['status'] === 'finished'): ?>
                    <button name="action" value="reopen" class="btn ghost">Вернуть в работу</button>
                    <button name="action" value="confirm" class="btn primary" data-confirm="Подтвердить результаты аттестации?">Подтвердить результаты</button>
                <?php else: ?>
                    <button name="action" value="reopen" class="btn ghost" data-confirm="Снова открыть аттестацию для изменений?">Открыть заново</button>
                <?php endif; ?>
                <?php if (!$isDraft): ?>
                <button name="action" value="delete_attestation" class="btn ghost danger"
                            data-confirm="Удалить аттестацию «<?= e($att['title']) ?>» со всеми станциями и результатами? Это нельзя отменить."><?= icon('trash') ?> Удалить аттестацию</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="invite-card">
        <div class="invite-card-label">Код для инструкторов</div>
        <button type="button" class="code-chip" data-copy="<?= e($att['join_code']) ?>" title="Нажмите, чтобы скопировать"><?= e($att['join_code']) ?></button>
        <div class="invite-card-actions">
            <button type="button" class="btn small qr-open" data-dialog="#qr-dialog"><?= icon('qr') ?> QR-код</button>
            <button type="button" class="btn small" data-copy="<?= e(base_url() . 'join.php?code=' . urlencode($att['join_code'])) ?>"><?= icon('copy') ?> Ссылка</button>
        </div>
        <?php if ($selfReg && in_array($att['status'], ['draft'], true)): ?>
            <button type="button" class="btn small primary reg-open" data-dialog="#qr-part"><?= icon('qr') ?> Регистрация аттестуемых</button>
        <?php endif; ?>
    </div>
</section>

<nav class="tabs" data-tabs>
    <a href="#instructors" data-tab="instructors">Инструкторы
        <?php if ($pending): ?><span class="count warn"><?= $pending ?></span><?php endif; ?></a>
    <a href="#stations" data-tab="stations">Станции
        <?php if ($adminStations): ?><span class="count"><?= count($adminStations) ?></span><?php endif; ?></a>
    <a href="#participants" data-tab="participants">Участники
        <?php if ($partList): ?><span class="count"><?= count($partList) ?></span><?php endif; ?></a>
    <a href="#results" data-tab="results">Результаты
        <?php if ($matrix['rows']): ?><span class="count"><?= count($matrix['rows']) ?></span><?php endif; ?></a>
</nav>

<!-- ===================== ИНСТРУКТОРЫ ===================== -->
<section class="tab-panel" id="instructors">
    <?php if ($isDraft): ?>
        <div class="section-head">
            <p class="muted small mt0">Нажмите на инструктора, чтобы назначить его на станцию.</p>
            <a class="btn small" href="invite.php?att=<?= $id ?>"><?= icon('send') ?> Пригласить инструкторов</a>
        </div>
    <?php endif; ?>
    <?php
    $requests = [];
    foreach ($members as $m) foreach ($judgesByUser[(int)$m['id']] ?? [] as $j) {
        if (!$j['approved'] && isset($stations[$j['sid']])) $requests[] = ['m' => $m, 'j' => $j, 's' => $stations[$j['sid']]];
    }
    ?>
    <?php if ($requests): ?>
        <div class="card requests-card">
            <h2 class="mt0">Заявки на станции <span class="count warn"><?= count($requests) ?></span></h2>
            <?php foreach ($requests as $rq): ?>
                <div class="request-row">
                    <div class="request-text">
                        <b><?= e($rq['m']['full_name']) ?></b>
                        <span class="muted small"><?= $rq['s']['created_by_admin'] ? 'выбрал(а) станцию управления' : 'предлагает свою станцию' ?></span>
                        <button type="button" class="station-link inline-link" data-dialog="#st-<?= $rq['j']['sid'] ?>"><?= e($rq['s']['name']) ?></button>
                    </div>
                    <form method="post" class="btn-row">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="station" value="<?= $rq['j']['sid'] ?>">
                        <input type="hidden" name="user" value="<?= (int)$rq['m']['id'] ?>">
                        <input type="hidden" name="tab" value="instructors">
                        <button name="action" value="approve_judge" class="btn small primary"><?= icon('check') ?> Подтвердить</button>
                        <?php if ($rq['s']['created_by_admin'] || $started): ?>
                            <button name="action" value="remove_judge" class="btn small" data-confirm="Отклонить заявку?">Отклонить</button>
                        <?php else: ?>
                            <button name="action" value="delete_station" class="btn small" data-confirm="Отклонить заявку и удалить станцию инструктора?">Отклонить</button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($invited): ?>
        <div class="invited-list">
            <span class="muted small">Ждут ответа на приглашение:</span>
            <?php foreach ($invited as $m): ?>
                <form method="post" class="invited-chip">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="user" value="<?= (int)$m['id'] ?>">
                    <input type="hidden" name="tab" value="instructors">
                    <span><?= e($m['full_name']) ?></span>
                    <button name="action" value="cancel_invite" class="icon-btn" title="Отменить приглашение" data-confirm="Отменить приглашение для <?= e($m['full_name']) ?>?"><?= icon('x') ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$members): ?>
        <div class="empty">
            Никто ещё не присоединился. Пригласите инструкторов, покажите QR-код или передайте код
            <b><?= e($att['join_code']) ?></b> — его вводят на главной странице.
        </div>
    <?php else: ?>
        <div class="people">
            <?php foreach ($members as $m):
                $mine = $judgesByUser[(int)$m['id']] ?? []; ?>
                <article class="person card <?= $assignFor === (int)$m['id'] ? 'highlight' : '' ?>" id="person-<?= (int)$m['id'] ?>">
                    <details class="assign-panel" <?= $assignFor === (int)$m['id'] ? 'open' : '' ?>>
                        <summary class="person-head" title="Нажмите, чтобы назначить на станцию">
                            <div class="avatar"><?= e(mb_substr($m['full_name'], 0, 1)) ?></div>
                            <div class="person-name">
                                <b><?= e($m['full_name']) ?></b>
                                <div class="muted small">@<?= e($m['username']) ?></div>
                            </div>
                            <?php if ($isDraft): ?><span class="btn small assign-btn"><?= $oneToOne && $mine ? 'Переназначить' : 'Назначить на станцию' ?> ▾</span><?php endif; ?>
                        </summary>
                        <?php if ($isDraft):
                            $mineIds = array_column($mine, 'sid');
                            $options = array_filter($adminStations, fn($s) => !in_array((int)$s['id'], $mineIds, true)); ?>
                            <div class="assign-body">
                                <?php if (!$adminStations): ?>
                                    <p class="muted small mt0">Станций пока нет. Создайте станцию — после этого вы вернётесь сюда и сможете назначить на неё инструктора.</p>
                                    <a class="btn primary small" href="station_new.php?att=<?= $id ?>&for=<?= (int)$m['id'] ?>"><?= icon('plus') ?> Создать станцию</a>
                                <?php else: ?>
                                    <?php if ($oneToOne && $mine): ?>
                                        <p class="muted small mt0">У инструктора уже есть станция — при новом назначении она будет снята.</p>
                                    <?php endif; ?>
                                    <?php if (!$options): ?>
                                        <p class="muted small mt0">Инструктор уже на всех станциях управления.</p>
                                    <?php endif; ?>
                                    <?php foreach ($options as $osid => $os):
                                        $busy = array_column($judgesByStation[$osid] ?? [], 'name'); ?>
                                        <form method="post">
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="station" value="<?= $osid ?>">
                                            <input type="hidden" name="user" value="<?= (int)$m['id'] ?>">
                                            <input type="hidden" name="tab" value="instructors">
                                            <button name="action" value="assign" class="pick-item"
                                                <?= $oneToOne && $busy ? 'data-confirm="На станции уже работает ' . e(implode(', ', $busy)) . ' — заменить?"' : '' ?>>
                                                <b><?= e($os['name']) ?></b>
                                                <span class="muted small">
                                                    <?= plural(count($criteria[$osid] ?? []), 'критерий', 'критерия', 'критериев') ?>
                                                    <?= $busy ? ' · ' . e(implode(', ', $busy)) : ' · свободна' ?>
                                                </span>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                    <a class="pick-item add" href="station_new.php?att=<?= $id ?>&for=<?= (int)$m['id'] ?>"><?= icon('plus') ?> Новая станция для этого инструктора</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </details>
                    <?php if (!$mine): ?>
                        <p class="muted small">Станцию пока не выбрал</p>
                    <?php endif; ?>
                    <?php foreach ($mine as $j):
                        $s = $stations[$j['sid']] ?? null;
                        if (!$s) continue;
                        $crit = $criteria[$j['sid']] ?? []; ?>
                        <details class="station-acc" <?= !$j['approved'] ? 'open' : '' ?>>
                            <summary>
                                <span class="station-name"><?= e($s['name']) ?></span>
                                <span class="badge <?= $j['approved'] ? 'ok' : 'warn' ?>"><?= $j['approved'] ? 'подтверждена' : 'ждёт подтверждения' ?></span>
                            </summary>
                            <div class="acc-body">
                                <div class="muted small">
                                    <?= $s['created_by_admin'] ? 'Станция управления' : 'Своя станция инструктора' ?> ·
                                    порог <?= (int)$s['pass_threshold'] ?>% ·
                                    <?= plural(count($crit), 'критерий', 'критерия', 'критериев') ?>
                                    <?php if (!empty($resCnt[$j['sid']])): ?> · оценено <?= $resCnt[$j['sid']] ?><?php endif; ?>
                                </div>
                                <?php if (!$crit): ?>
                                    <p class="muted">Критерии ещё не заданы.</p>
                                <?php else: ?>
                                    <ol class="crit-list">
                                        <?php foreach ($crit as $c): ?>
                                            <li><span><?= e($c['name']) ?></span>
                                                <span class="score-set"><?php foreach (parse_scores($c['allowed_scores']) as $sc): ?><i><?= e($sc) ?></i><?php endforeach; ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                                <div class="btn-row">
                                    <?php if (!$j['approved']): ?>
                                        <form method="post">
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="station" value="<?= $j['sid'] ?>">
                                            <input type="hidden" name="user" value="<?= (int)$m['id'] ?>">
                                            <button name="action" value="approve_judge" class="btn primary"><?= icon('check') ?> Подтвердить станцию</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="station" value="<?= $j['sid'] ?>">
                                            <input type="hidden" name="user" value="<?= (int)$m['id'] ?>">
                                            <?php if ($s['created_by_admin']): ?>
                                                <button name="action" value="remove_judge" class="btn ghost" data-confirm="Отклонить выбор станции?">Отклонить</button>
                                            <?php elseif (!$started): ?>
                                                <input type="hidden" name="tab" value="instructors">
                                                <button name="action" value="delete_station" class="btn ghost" data-confirm="Удалить станцию инструктора?">Отклонить и удалить</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="station" value="<?= $j['sid'] ?>">
                                            <input type="hidden" name="user" value="<?= (int)$m['id'] ?>">
                                            <button name="action" value="revoke_judge" class="btn ghost small" data-confirm="Отозвать доступ инструктора к станции?">Отозвать доступ</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($isDraft && !$started): ?>
                                        <a class="btn ghost small" href="criteria_edit.php?station=<?= $j['sid'] ?>">Изменить критерии</a>
                                        <?php if ($j['approved']): ?>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="station" value="<?= $j['sid'] ?>">
                                                <input type="hidden" name="tab" value="instructors">
                                                <button name="action" value="delete_station" class="btn ghost small danger"
                                                        data-confirm="Удалить станцию «<?= e($s['name']) ?>» полностью<?= $s['created_by_admin'] ? '' : ' (её создал инструктор)' ?>?"><?= icon('trash') ?> Удалить станцию</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                    <?php if ($isDraft): ?>
                        <form method="post" class="person-foot">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="user" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="tab" value="instructors">
                            <button name="action" value="remove_member" class="btn ghost small danger"
                                    data-confirm="Исключить <?= e($m['full_name']) ?> из аттестации? Он будет снят со всех станций, выставленные оценки сохранятся."><?= icon('x') ?> Исключить из аттестации</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ===================== УЧАСТНИКИ ===================== -->
<section class="tab-panel" id="participants">
    <div class="card">
        <h2 class="mt0">Добавить аттестуемого</h2>
        <p class="muted small">
            <?= $selfReg
                ? 'Аттестуемые регистрируются сами по QR-коду или ссылке «Регистрация аттестуемых» — и сразу появляются в этом списке. Можно добавить человека и вручную. Инструкторы выбирают аттестуемого из списка.'
                : ($byAdmin
                ? 'Список заполняет сотрудник управления: инструкторы выбирают аттестуемого из этого списка и не могут добавлять новых.'
                : 'Сейчас инструкторы вносят аттестуемых сами на станции. Включите «Список аттестуемых заполняет сотрудник управления» или «Самостоятельная регистрация аттестуемых» в настройках (шестерёнка), чтобы они выбирали только из этого списка.') ?>
        </p>
        <form method="post" class="person-form">
            <input type="hidden" name="id" value="<?= $id ?>">
            <label>Фамилия <input name="last_name" required autocomplete="off"></label>
            <label>Имя <input name="first_name" required autocomplete="off"></label>
            <button name="action" value="add_participant" class="btn primary"><?= icon('plus') ?> Добавить</button>
        </form>
    </div>

    <?php if (!$partList): ?>
        <div class="empty">Аттестуемых пока нет.</div>
    <?php else: ?>
        <div class="section-head">
            <span class="muted small">Всего: <?= count($partList) ?></span>
            <input type="search" class="search" placeholder="Поиск по фамилии" data-filter="#participants .part-row">
        </div>
        <div class="part-list">
            <?php foreach ($partList as $pid => $p): ?>
                <div class="part-row">
                    <div class="part-name">
                        <b><?= e($p['name']) ?> <?php if (!empty($p['self'])): ?><span class="badge silver">зарегистрировался сам</span><?php endif; ?></b>
                        <span class="muted small">
                            <?= !empty($p['num']) ? '№' . (int)$p['num'] . ' · ' : '' ?><?= !empty($p['birth']) ? e(date('d.m.Y', strtotime($p['birth']))) . ' · ' : '' ?>
                            <?= $p['roster'] ? 'в списке' : 'добавлен инструктором' ?>
                            · <?= $p['stations'] ? 'пройдено станций: ' . $p['stations'] : 'станции ещё не проходил' ?>
                        </span>
                    </div>
                    <form method="post" class="inline part-actions">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="participant" value="<?= $pid ?>">
                        <?php if (!empty($p['self'])): ?>
                            <button name="action" value="participant_password" class="btn small ghost" data-confirm="Выдать <?= e($p['name']) ?> временный пароль? Старый перестанет работать."><?= icon('key') ?> Новый пароль</button>
                        <?php endif; ?>
                        <button name="action" value="remove_participant" class="btn small ghost danger"
                                data-confirm="Удалить <?= e($p['name']) ?> из аттестации?<?= $p['stations'] ? ' Его результаты на станциях тоже удалятся.' : '' ?><?= !empty($p['self']) ? ' Учётная запись аттестуемого будет удалена.' : '' ?>"><?= icon('trash') ?> Удалить</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ===================== РЕЗУЛЬТАТЫ ===================== -->
<section class="tab-panel" id="results">
    <?php if (!$matrix['rows']): ?>
        <div class="empty">Результатов пока нет. Они появятся здесь, как только инструкторы начнут оценивать аттестуемых.</div>
    <?php else: ?>
        <div class="section-head">
            <div class="verdict-summary">
                <span class="badge ok">Сдали: <?= $verdictCnt['pass'] ?></span>
                <span class="badge err">Не сдали: <?= $verdictCnt['fail'] + ($finished ? $verdictCnt['pending'] : 0) ?></span>
                <?php if (!$finished && $verdictCnt['pending']): ?><span class="badge">В процессе: <?= $verdictCnt['pending'] ?></span><?php endif; ?>
            </div>
            <div class="btn-row">
                <a class="btn small" href="stats.php?id=<?= $id ?>"><?= icon('chart') ?> Статистика</a>
                <a class="btn ghost small" href="<?= $self ?>&export=xlsx" title="Таблица Excel с формулами: итоги и баллы по критериям"><?= icon('download') ?> Excel</a>
            </div>
        </div>
        <div class="table-wrap">
            <table class="matrix">
                <thead>
                <tr>
                    <th class="sticky">Аттестуемый</th>
                    <?php foreach ($matrix['stations'] as $s): ?>
                        <th class="m-hide"><?= e($s['name']) ?><div class="th-sub">из <?= $s['max'] ?> · порог <?= $s['threshold'] ?>%</div></th>
                    <?php endforeach; ?>
                    <th class="m-hide">Станций сдано<div class="th-sub">из <?= $matrix['st_count'] ?></div></th>
                    <th class="m-hide">Результат<div class="th-sub">среднее % станций</div></th>
                    <th>Сдал / не сдал<div class="th-sub"><?= e(verdict_rule($matrix)) ?></div></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matrix['rows'] as $pid => $row): ?>
                    <tr class="row-link" data-href="participant.php?att=<?= $id ?>&p=<?= $pid ?>" data-detail="#rd-<?= $pid ?>" data-title="<?= e($row['name']) ?>">
                        <th class="sticky"><a href="participant.php?att=<?= $id ?>&p=<?= $pid ?>"><?= e($row['name']) ?></a></th>
                        <?php foreach ($matrix['stations'] as $sid => $s):
                            $cell = $row['cells'][$sid] ?? null; ?>
                            <?php if (!$cell): ?>
                                <td class="none m-hide">—</td>
                            <?php else: ?>
                                <td class="m-hide <?= $cell['passed'] === null ? 'mid' : ($cell['passed'] ? 'good' : 'low') ?>"
                                    title="<?= $cell['cnt'] < $s['max'] ? 'Оценено ' . $cell['cnt'] . ' из ' . $s['max'] . ' критериев' : fmt_pct(pct($cell['sum'], $s['max'])) . ($cell['passed'] ? ' — сдал' : ' — не сдал') ?>">
                                    <?= fmt_pct(pct($cell['sum'], $s['max'])) ?><?= $cell['cnt'] < $s['max'] ? '<sup>*</sup>' : '' ?>
                                    <div class="th-sub"><?= fmt_score($cell['sum']) ?> из <?= $s['max'] ?></div>
                                </td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <td class="total m-hide <?= $matrix['mode'] === 'stations' && $matrix['needed'] ? ($row['st_passed'] >= $matrix['needed'] ? 'good' : '') : '' ?>"><?= $row['st_passed'] ?> / <?= $matrix['st_count'] ?></td>
                        <td class="total m-hide <?= $matrix['mode'] === 'avg' && $row['pct'] >= $matrix['threshold'] ? 'good' : '' ?>"><?= fmt_pct($row['pct']) ?><div class="th-sub"><?= fmt_score($row['total']) ?> из <?= $matrix['max_total'] ?> б.</div></td>
                        <td><?= verdict_badge($row['verdict'], $finished) ?>
                            <div class="th-sub mob-only">станций <?= $row['st_passed'] ?>/<?= $matrix['st_count'] ?> · результат <?= fmt_pct($row['pct']) ?></div></td>
                    </tr>
                    <tr class="row-detail" id="rd-<?= $pid ?>" hidden>
                        <td colspan="<?= count($matrix['stations']) + 4 ?>">
                            <ul class="detail-list">
                                <?php foreach ($matrix['stations'] as $sid => $s): $cell = $row['cells'][$sid] ?? null; ?>
                                    <li><span><?= e($s['name']) ?></span>
                                        <b class="<?= !$cell ? 'none' : ($cell['passed'] === null ? 'mid' : ($cell['passed'] ? 'good' : 'low')) ?>">
                                            <?= $cell ? fmt_pct(pct($cell['sum'], $s['max'])) . ' <small>(' . fmt_score($cell['sum']) . ' / ' . $s['max'] . ')</small>' : '—' ?></b></li>
                                <?php endforeach; ?>
                                <li class="detail-total"><span>Сдано станций</span><b><?= $row['st_passed'] ?> / <?= $matrix['st_count'] ?></b></li>
                                <li class="detail-total"><span>Результат (среднее по станциям)</span><b><?= fmt_pct($row['pct']) ?> <small>(<?= fmt_score($row['total']) ?> / <?= $matrix['max_total'] ?> б.)</small></b></li>
                            </ul>
                            <a class="btn small" href="participant.php?att=<?= $id ?>&p=<?= $pid ?>">Подробно по критериям →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">Зелёным — станция сдана, красным — не сдана. <sup>*</sup> — оценены не все критерии.
            <?php if ($matrix['mode'] === 'avg'): ?>
                Итог аттестации — средний процент по станциям (каждая станция весит одинаково, сколько бы в ней ни было критериев) не ниже <?= $matrix['threshold'] ?>%. Неоценённая станция пока считается как 0%.
            <?php else: ?>
                Итог аттестации — по числу сданных станций: <?= e(needed_text($matrix['needed'], $matrix['st_count'])) ?> (порог <?= $matrix['threshold'] ?>% станций). Станция сдана, если набран её порог.
            <?php endif; ?>
            <?php if (!$finished): ?>«В процессе» — оценено ещё не всё и итог пока не определён.<?php endif; ?></p>
    <?php endif; ?>
</section>

<!-- ===================== СТАНЦИИ УПРАВЛЕНИЯ ===================== -->
<section class="tab-panel" id="stations">
    <?php if ($isDraft): ?>
        <div class="section-head">
            <p class="muted small mt0">Нажмите на название станции, чтобы посмотреть её критерии.</p>
            <a class="btn primary" href="station_new.php?att=<?= $id ?>&wizard=1"><?= icon('plus') ?> <?= $started ? 'Добавить станцию' : 'Создать станцию' ?></a>
        </div>
        <?php if ($started): ?><div class="alert warn">Приём аттестуемых уже начался — станции, которые были до этого, их критерии и пороги изменить нельзя. Новую станцию добавить можно: её можно настраивать, пока по ней нет ни одной оценки.</div><?php endif; ?>
    <?php endif; ?>

    <?php if (!$adminStations): ?>
        <div class="empty">Станций пока нет.</div>
    <?php else: ?>
        <div class="tiles">
            <?php foreach ($adminStations as $sid => $s):
                $judges = $judgesByStation[$sid] ?? [];
                $assignedIds = array_column($judges, 'uid');
                $free = array_filter($members, fn($m) => !in_array((int)$m['id'], $assignedIds, true));
                $critCnt = count($criteria[$sid] ?? []); ?>
                <article class="card station-card">
                    <div class="tile-top">
                        <button type="button" class="station-link tile-title" data-dialog="#st-<?= $sid ?>"><?= e($s['name']) ?></button>
                        <span class="muted small"><?= $critCnt ? plural($critCnt, 'критерий', 'критерия', 'критериев') : 'без критериев' ?> · порог <?= (int)$s['pass_threshold'] ?>%
                            <?php if (!empty($s['added_late'])): ?><span class="badge late">добавлена после начала</span><?php endif; ?></span>
                    </div>

                    <?php if ($judges): ?>
                        <ul class="judges">
                            <?php foreach ($judges as $j): ?>
                                <li>
                                    <span><?= e($j['name']) ?></span>
                                    <span class="badge <?= $j['approved'] ? 'ok' : 'warn' ?>"><?= $j['approved'] ? 'допущен' : 'ждёт' ?></span>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="station" value="<?= $sid ?>">
                                        <input type="hidden" name="user" value="<?= $j['uid'] ?>">
                                        <input type="hidden" name="tab" value="stations">
                                        <button name="action" value="remove_judge" class="icon-btn" title="Снять со станции" data-confirm="Снять инструктора со станции?"><?= icon('x') ?></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted small">Инструктор не назначен</p>
                    <?php endif; ?>

                    <div class="btn-row">
                        <details class="dropdown">
                            <summary class="btn small"><?= icon('user') ?> <?= $oneToOne && $judges ? 'Переназначить инструктора' : 'Назначить инструктора' ?></summary>
                            <div class="dropdown-body">
                                <?php if (!$members): ?>
                                    <p class="muted small">К аттестации ещё никто не присоединился.</p>
                                <?php elseif (!$free): ?>
                                    <p class="muted small">Все инструкторы уже на этой станции.</p>
                                <?php else: ?>
                                    <?php if ($oneToOne && $judges): ?><p class="muted small">Текущий инструктор будет заменён.</p><?php endif; ?>
                                    <?php foreach ($free as $m): ?>
                                        <form method="post">
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="station" value="<?= $sid ?>">
                                            <input type="hidden" name="user" value="<?= (int)$m['id'] ?>">
                                            <?php $hasSt = !empty($judgesByUser[(int)$m['id']]); ?>
                                            <button name="action" value="assign" class="pick-item"><?= e($m['full_name']) ?>
                                                <?php if ($hasSt): ?><span class="muted small"> · уже есть станция<?= $oneToOne ? ' (будет снята)' : '' ?></span><?php endif; ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                        <?php $stLocked = station_locked($pdo, $sid); ?>
                        <?php if ($isDraft && !$stLocked): ?>
                            <a class="btn ghost small" href="criteria_edit.php?station=<?= $sid ?>">Критерии</a>
                            <a class="btn ghost small" href="threshold.php?station=<?= $sid ?>">Порог</a>
                        <?php endif; ?>
                        <?php if ($isDraft && !$stLocked): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="station" value="<?= $sid ?>">
                                <button name="action" value="delete_station" class="btn ghost small danger" data-confirm="Удалить станцию «<?= e($s['name']) ?>»?">Удалить</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php foreach ($stations as $sid => $s):
    station_dialog('st-' . $sid, $s, $criteria[$sid] ?? [],
        $isDraft && !station_locked($pdo, (int)$sid) ? '<a class="btn primary" href="criteria_edit.php?station=' . $sid . '">' . icon('edit') . ' Редактировать</a>' : '');
endforeach; ?>

<!-- ===================== НАСТРОЙКИ (окно) ===================== -->
<dialog class="modal" id="settings-dialog">
    <form method="post" class="stack">
        <div class="modal-head">
            <h2 class="mt0"><?= icon('gear') ?> Настройки аттестации</h2>
            <button type="button" class="icon-btn" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button>
        </div>
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php att_threshold_field((int)$att['pass_threshold'], $started, attestation_matrix($pdo, $id)['st_count'], $att['verdict_mode'] ?? 'avg'); ?>
        <?php settings_switches($att); ?>
        <div class="btn-row">
            <button type="button" class="btn ghost" data-dialog-close>Отменить</button>
            <button name="action" value="save_settings" class="btn primary">Сохранить настройки</button>
        </div>
    </form>
</dialog>

<!-- ===================== QR-ПРИГЛАШЕНИЕ ===================== -->
<?php $joinUrl = base_url() . 'join.php?code=' . urlencode($att['join_code']); ?>
<dialog class="modal qr-modal" id="qr-dialog">
    <div class="modal-head">
        <h2 class="mt0">Приглашение для инструкторов</h2>
        <button type="button" class="icon-btn" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button>
    </div>
    <p class="muted small">Инструктор наводит камеру телефона на код, входит или регистрируется — и сразу попадает в аттестацию «<?= e($att['title']) ?>».</p>
    <div class="qr-box" data-qr="<?= e($joinUrl) ?>"><span class="muted small">Загружаем QR-код…</span></div>
    <div class="qr-link">
        <input readonly value="<?= e($joinUrl) ?>" onclick="this.select()">
        <button type="button" class="btn small" data-copy="<?= e($joinUrl) ?>"><?= icon('copy') ?> Копировать</button>
    </div>
    <p class="center"><span class="muted small">Код вручную:</span> <b class="mono"><?= e($att['join_code']) ?></b></p>
</dialog>

<?php if ($selfReg): $regCode = att_reg_code($pdo, $att); $regUrl = base_url() . 'reg.php?code=' . urlencode($regCode); ?>
<dialog class="modal qr-modal" id="qr-part">
    <div class="modal-head">
        <h2 class="mt0">Регистрация аттестуемых</h2>
        <button type="button" class="icon-btn" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button>
    </div>
    <p class="muted small">Аттестуемый наводит камеру на код, вводит фамилию, имя, отчество, пол и дату рождения — и попадает в список участников «<?= e($att['title']) ?>». Покажите код на экране или распечатайте.</p>
    <div class="qr-box" data-qr="<?= e($regUrl) ?>"><span class="muted small">Загружаем QR-код…</span></div>
    <div class="qr-link">
        <input readonly value="<?= e($regUrl) ?>" onclick="this.select()">
        <button type="button" class="btn small" data-copy="<?= e($regUrl) ?>"><?= icon('copy') ?> Копировать</button>
    </div>
    <p class="center"><span class="muted small">Или откройте сайт → «Я аттестуемый» и введите код</span> <b class="mono"><?= e($regCode) ?></b></p>
    <p class="muted small center mt0">Это отдельный код только для аттестуемых — код инструкторов им не показывается.</p>
</dialog>
<?php endif; ?>

<!-- ===================== УПРАВЛЕНИЕ ===================== -->

<?php page_end();
