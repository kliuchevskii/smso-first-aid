<?php
// Все уведомления пользователя
require_once 'functions.php';
require_login();

if (is_post() && ($_POST['action'] ?? '') === 'push_prefs') {
    // разделы, которые НЕ нужно присылать на телефон
    $on  = array_intersect((array)($_POST['cats'] ?? []), array_keys(PUSH_CATS));
    $off = array_values(array_diff(array_keys(PUSH_CATS), $on));
    $pdo->prepare("UPDATE users SET push_off = ? WHERE id = ?")->execute([implode(',', $off), uid()]);
    flash('Настройки уведомлений сохранены');
    $back = (string)($_POST['back'] ?? '');
    redirect(in_array($back, ['notifications.php', 'profile.php?tab=app'], true) ? $back : 'notifications.php');
}
if (is_post() && ($_POST['action'] ?? '') === 'clear') {
    $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([uid()]);
    flash('Уведомления очищены', 'warn');
    redirect('notifications.php');
}

$q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 200");
$q->execute([uid()]);
$list = $q->fetchAll();
// открыли страницу — всё прочитано (и всплывающие окна уже не нужны)
$now = date('Y-m-d H:i:s');
$pdo->prepare("UPDATE notifications SET read_at = ?, seen_at = COALESCE(seen_at, ?) WHERE user_id = ? AND read_at IS NULL")->execute([$now, $now, uid()]);

$icons = ['invite' => 'mail', 'role' => 'shield', 'info' => 'bell', 'doc' => 'file'];
page_start('Уведомления', ['section' => 'notifications', 'width' => 'narrow']);
?>
<section class="page-head">
    <div>
        <h1>Уведомления</h1>
        <div class="muted">Приглашения в аттестации, новые полномочия и другие события.</div>
    </div>
    <?php if ($list): ?>
        <form method="post" class="inline">
            <input type="hidden" name="action" value="clear">
            <button class="btn small ghost" data-confirm="Удалить все уведомления?"><?= icon('trash') ?> Очистить</button>
        </form>
    <?php endif; ?>
</section>

<?php push_card('notifications.php'); ?>

<h2>Все уведомления</h2>
<?php if (!$list): ?>
    <div class="empty">Уведомлений пока нет.</div>
<?php else: ?>
    <div class="notif-list">
        <?php foreach ($list as $n): $new = $n['read_at'] === null; ?>
            <article class="notif card <?= $new ? 'is-new' : '' ?>">
                <span class="notif-ico"><?= icon($icons[$n['kind'] ?? ''] ?? 'bell') ?></span>
                <div class="notif-body">
                    <div class="notif-text"><?= e($n['message']) ?><?= $new ? ' <span class="badge brand">новое</span>' : '' ?></div>
                    <div class="muted small"><?= e(date('d.m.Y H:i', strtotime((string)$n['created_at']))) ?></div>
                    <?php if (($n['kind'] ?? '') === 'invite'): ?>
                        <?php $st = member_status($pdo, (int)$n['ref_id'], uid()); ?>
                        <?php if ($st === 'invited'): ?>
                            <?= invite_buttons($pdo, (int)$n['ref_id'], 'notifications.php') ?>
                        <?php elseif ($st === 'active'): ?>
                            <a class="btn small" href="attestation_instructor.php?id=<?= (int)$n['ref_id'] ?>">Открыть аттестацию</a>
                        <?php else: ?>
                            <span class="muted small">Приглашение отклонено или отменено</span>
                        <?php endif; ?>
                    <?php elseif ($n['link']): ?>
                        <a class="btn small" href="<?= e($n['link']) ?>">Открыть</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php page_end();
