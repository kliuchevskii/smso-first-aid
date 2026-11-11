<?php
// Все зарегистрированные инструкторы — для приглашения в аттестации
require_once 'functions.php';
require_role('admin');

$list = $pdo->prepare("
    SELECT u.id, u.full_name, u.username, u.email, u.role,
           (SELECT COUNT(*) FROM attestation_members m JOIN attestations a ON a.id = m.attestation_id
             WHERE m.user_id = u.id AND a.status = 'draft') AS active_cnt
    FROM users u
    WHERE u.role IN ('instructor', 'admin', 'sysadmin') AND u.id <> ?
    ORDER BY u.full_name
");
$list->execute([uid()]);
$people = $list->fetchAll();

page_start('Инструкторы', ['section' => 'manage']);
manage_tabs('instructors');
?>
<section class="page-head">
    <div>
        <h2 class="mt0">Инструкторы</h2>
        <div class="muted">Все зарегистрированные. Нажмите «Пригласить», чтобы выбрать аттестацию.</div>
    </div>
    <input type="search" class="search" placeholder="Поиск по ФИО, логину, почте" data-filter=".people-list .person-row">
</section>

<?php if (!$people): ?>
    <div class="empty">Пока никто, кроме вас, не зарегистрирован.</div>
<?php else: ?>
    <div class="people-list">
        <?php foreach ($people as $p): ?>
            <div class="person-row card">
                <div class="avatar"><?= e(mb_substr($p['full_name'], 0, 1)) ?></div>
                <div class="person-name">
                    <b><?= e($p['full_name']) ?></b>
                    <div class="muted small">@<?= e($p['username']) ?><?= $p['email'] ? ' · ' . e($p['email']) : '' ?></div>
                </div>
                <div class="person-meta">
                    <?php if ($p['role'] === 'admin'): ?><span class="badge info">сотрудник управления</span><?php elseif ($p['role'] === 'sysadmin'): ?><span class="badge gold">администратор</span><?php endif; ?>
                    <span class="muted small">в идущих аттестациях: <?= (int)$p['active_cnt'] ?></span>
                </div>
                <a class="btn small primary" href="invite.php?user=<?= (int)$p['id'] ?>"><?= icon('send') ?> Пригласить</a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php page_end();
