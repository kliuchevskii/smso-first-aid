<?php
// Приглашение инструкторов в аттестацию.
//   invite.php?user=ID — выбрать аттестацию для этого инструктора
//   invite.php?att=ID  — выбрать инструкторов для этой аттестации
require_once 'functions.php';
require_role('admin');

$userId = (int)($_GET['user'] ?? $_POST['user'] ?? 0);
$attId  = (int)($_GET['att'] ?? $_POST['att'] ?? 0);

/** Добавить участников, вернуть число новых */
function add_members(PDO $pdo, int $attId, array $userIds): int {
    $n = 0;
    $att = get_attestation($pdo, $attId);
    if (!$att) return 0;
    foreach (array_unique(array_map('intval', $userIds)) as $u) {
        if ($u > 0 && invite_member($pdo, $att, $u)) $n++;
    }
    return $n;
}

/* ===== Режим: инструктор → аттестация ===== */
if ($userId) {
    $uq = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role IN ('instructor', 'admin', 'sysadmin')");
    $uq->execute([$userId]);
    $person = $uq->fetch();
    if (!$person) {
        flash('Инструктор не найден', 'err');
        redirect('instructors.php');
    }
    if (is_post() && $attId && get_attestation($pdo, $attId)) {
        $n = add_members($pdo, $attId, [$userId]);
        flash($n ? "Приглашение отправлено: {$person['full_name']} получит уведомление" : 'Инструктор уже участвует в этой аттестации', $n ? 'ok' : 'warn');
        redirect("invite.php?user=$userId");
    }
    $aq = $pdo->prepare("
        SELECT a.*, (SELECT MAX(m.status) FROM attestation_members m WHERE m.attestation_id = a.id AND m.user_id = ?) AS is_in
        FROM attestations a WHERE a.status IN ('prep', 'draft') ORDER BY a.id DESC
    ");
    $aq->execute([$userId]);
    $atts = $aq->fetchAll();

    page_start('Пригласить', ['back' => 'instructors.php', 'width' => 'narrow', 'section' => 'instructors']);
    ?>
    <section class="page-head">
        <div>
            <h1>Пригласить в аттестацию</h1>
            <div class="muted"><?= e($person['full_name']) ?> · @<?= e($person['username']) ?></div>
        </div>
    </section>
    <?php if (!$atts): ?>
        <div class="empty">Нет идущих аттестаций. <a href="attestation_wizard.php">Создайте аттестацию</a> — в мастере тоже можно пригласить инструкторов.</div>
    <?php else: ?>
        <div class="pick-list">
            <?php foreach ($atts as $a): ?>
                <form method="post" class="pick-row">
                    <input type="hidden" name="user" value="<?= $userId ?>">
                    <input type="hidden" name="att" value="<?= (int)$a['id'] ?>">
                    <div>
                        <b><?= e($a['title']) ?></b>
                        <div class="muted small">Код <?= e($a['join_code']) ?></div>
                    </div>
                    <?php if ($a['is_in'] === 'active'): ?>
                        <span class="badge ok"><?= icon('check') ?> уже участвует</span>
                    <?php elseif ($a['is_in'] === 'invited'): ?>
                        <span class="badge warn">приглашение отправлено</span>
                    <?php else: ?>
                        <button class="btn small primary"><?= icon('send') ?> Пригласить</button>
                    <?php endif; ?>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
    page_end();
    exit;
}

/* ===== Режим: аттестация → инструкторы ===== */
$att = get_attestation($pdo, $attId);
if (!$att) {
    flash('Аттестация не найдена', 'err');
    redirect('manage.php');
}
$back = "attestation.php?id=$attId#instructors";
if (is_post()) {
    $n = add_members($pdo, $attId, (array)($_POST['users'] ?? []));
    flash($n ? 'Приглашения отправлены (' . $n . '), инструкторы получат уведомление' : 'Никто не выбран', $n ? 'ok' : 'warn');
    redirect($back);
}
$pq = $pdo->prepare("
    SELECT u.id, u.full_name, u.username, u.role FROM users u
    WHERE u.role IN ('instructor', 'admin', 'sysadmin') AND u.id <> ?
      AND u.id NOT IN (SELECT user_id FROM attestation_members WHERE attestation_id = ?)
    ORDER BY u.full_name
");
$pq->execute([uid(), $attId]);
$people = $pq->fetchAll();

page_start('Пригласить инструкторов', ['back' => $back, 'width' => 'narrow']);
?>
<section class="page-head">
    <div>
        <h1>Пригласить инструкторов</h1>
        <div class="muted"><?= e($att['title']) ?></div>
    </div>
</section>
<?php if (!$people): ?>
    <div class="empty">Все зарегистрированные инструкторы уже участвуют в этой аттестации.</div>
<?php else: ?>
    <form method="post" class="card">
        <input type="hidden" name="att" value="<?= $attId ?>">
        <?php invite_checklist($people); ?>
        <div class="btn-row mt">
            <a class="btn ghost" href="<?= e($back) ?>">Отменить</a>
            <button class="btn primary"><?= icon('send') ?> Пригласить выбранных</button>
        </div>
    </form>
<?php endif; ?>
<?php page_end();
