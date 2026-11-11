<?php
// Карточка пользователя для администратора. Открывается во всплывающем окне из списка (admin.php).
define('SYSADMIN_PAGE', true);
require_once 'functions.php';
require_login();
if (!is_sysadmin()) { http_response_code(403); exit('<p class="muted pad">Нет доступа.</p>'); }

$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare("SELECT u.*,
        (SELECT COUNT(*) FROM attestation_members m WHERE m.user_id = u.id AND m.status = 'active') AS att_cnt,
        (SELECT COUNT(*) FROM attestations a WHERE a.created_by = u.id) AS own_cnt,
        (SELECT COUNT(*) FROM lesson_done d WHERE d.user_id = u.id AND d.completed_at IS NOT NULL) AS lessons_cnt,
        (SELECT COUNT(*) FROM password_resets r WHERE r.user_id = u.id AND r.status = 'pending') AS reset_cnt
    FROM users u WHERE u.id = ?");
$q->execute([$id]);
$x = $q->fetch();
if (!$x) { http_response_code(404); exit('<p class="muted pad">Пользователь не найден.</p>'); }

$self = $id === uid();
$isPart = $x['role'] === 'participant';
$tabs = $isPart ? ['main' => 'Аттестуемый', 'access' => 'Доступ'] : ['main' => 'Основное', 'role' => 'Роль', 'access' => 'Доступ'];
$part = $partAtt = null;
if ($isPart) {
    $pq = $pdo->prepare("SELECT p.*, (SELECT COUNT(DISTINCT r.station_id) FROM results r WHERE r.participant_id = p.id) AS st_cnt FROM participants p WHERE p.id = ?");
    $pq->execute([(int)$x['participant_id']]);
    $part = $pq->fetch() ?: null;
    $partAtt = $x['attestation_id'] ? get_attestation($pdo, (int)$x['attestation_id']) : null;
}
$tab = isset($tabs[$_GET['ut'] ?? '']) ? $_GET['ut'] : 'main';
$ret = preg_replace('/[^a-z0-9_=&%.\-+]/i', '', (string)($_GET['ret'] ?? ''));
$roles = [
    'instructor' => 'Оценивает аттестуемых на станциях, проходит курсы.',
    'admin'      => 'Создаёт и ведёт аттестации в разделе «Управление», приглашает инструкторов. В «Аттестациях» работает как инструктор.',
    'sysadmin'   => 'Всё, что сотрудник управления, плюс раздел «Администрирование»: учётные записи, роли и коды доступа.',
];
$roleBadge = ['sysadmin' => 'gold', 'admin' => 'info', 'instructor' => ''];
$hidden = function () use ($id, $ret) {
    return '<input type="hidden" name="user" value="' . $id . '"><input type="hidden" name="ret" value="' . e($ret) . '">';
};
?>
<div class="modal-head user-head">
    <span class="avatar"><?= e(initials($x['full_name'])) ?></span>
    <div class="modal-title">
        <h2><?= e($x['full_name']) ?></h2>
        <small><?= $isPart ? 'Аттестуемый · временная учётная запись' : '@' . e($x['username']) . ' · ' . e(role_name($x['role'])) ?><?= $self ? ' · это вы' : '' ?></small>
    </div>
    <button type="button" class="icon-btn" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button>
</div>

<nav class="seg-tabs mtabs">
    <?php foreach ($tabs as $k => $label): ?>
        <button type="button" class="<?= $k === $tab ? 'is-on' : '' ?>" data-mtab="<?= $k ?>"><?= e($label) ?></button>
    <?php endforeach; ?>
</nav>

<?php if ($isPart): ?>
<div class="mpanel" data-panel="main" <?= $tab === 'main' ? '' : 'hidden' ?>>
    <div class="alert info mt0">Зарегистрировался сам на одну аттестацию. Учётная запись удалится вместе с аттестацией.</div>
    <dl class="dl">
        <dt>Аттестация</dt><dd><?= $partAtt ? '«' . e($partAtt['title']) . '» <span class="badge ' . status_class($partAtt['status']) . '">' . e(status_label($partAtt['status'])) . '</span>' : 'удалена' ?></dd>
        <?php $pnum = $partAtt ? participant_num($pdo, (int)$partAtt['id'], (int)$x['participant_id']) : null; ?>
        <dt>Номер</dt><dd><?= $pnum ? '№' . $pnum . ' в аттестации' : '—' ?></dd>
        <?php if ($part): ?>
            <dt>Фамилия</dt><dd><?= e($part['last_name']) ?></dd>
            <dt>Имя</dt><dd><?= e($part['first_name']) ?></dd>
            <dt>Отчество</dt><dd><?= e($part['middle_name'] ?: '—') ?></dd>
            <dt>Пол</dt><dd><?= ['m' => 'мужской', 'f' => 'женский'][$part['gender'] ?? ''] ?? '—' ?></dd>
            <dt>Дата рождения</dt><dd><?= $part['birth_date'] ? e(date('d.m.Y', strtotime($part['birth_date']))) : '—' ?></dd>
            <dt>Станций пройдено</dt><dd><?= (int)$part['st_cnt'] ?></dd>
        <?php endif; ?>
    </dl>
    <?php if ($partAtt): ?><a class="btn block" href="attestation.php?id=<?= (int)$partAtt['id'] ?>#participants"><?= icon('list') ?> Открыть аттестацию</a><?php endif; ?>
</div>
<?php else: ?>
<div class="mpanel" data-panel="main" <?= $tab === 'main' ? '' : 'hidden' ?>>
    <div class="kpis">
        <div class="kpi"><b><?= (int)$x['att_cnt'] ?></b><span>как инструктор</span></div>
        <div class="kpi"><b><?= (int)$x['own_cnt'] ?></b><span>ведёт</span></div>
        <div class="kpi"><b><?= (int)$x['lessons_cnt'] ?></b><span>уроков пройдено</span></div>
    </div>
    <form method="post" action="admin.php" class="stack">
        <input type="hidden" name="action" value="save_user"><?= $hidden() ?>
        <label>Фамилия Имя Отчество <input name="full_name" value="<?= e($x['full_name']) ?>" required></label>
        <div class="grid-2">
            <label>Логин <input name="username" value="<?= e($x['username']) ?>" pattern="[A-Za-z0-9_.\-]{3,32}" required></label>
            <label>Почта <input type="email" name="email" value="<?= e($x['email'] ?? '') ?>"></label>
        </div>
        <button class="btn primary block"><?= icon('save') ?> Сохранить</button>
    </form>
</div>

<div class="mpanel" data-panel="role" <?= $tab === 'role' ? '' : 'hidden' ?>>
    <form method="post" action="admin.php" class="stack">
        <input type="hidden" name="action" value="set_role"><?= $hidden() ?>
        <fieldset class="choice">
            <legend>Роль на платформе</legend>
            <?php foreach ($roles as $k => $desc): ?>
                <label class="choice-item">
                    <input type="radio" name="role" value="<?= $k ?>" <?= $x['role'] === $k ? 'checked' : '' ?> <?= $self && $k !== 'sysadmin' ? 'disabled' : '' ?>>
                    <span><b><?= e(role_name($k)) ?></b><small><?= e($desc) ?></small></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php if ($self): ?>
            <p class="muted small mt0">Свою роль администратора снять нельзя — попросите другого администратора.</p>
        <?php else: ?>
            <button class="btn primary block"><?= icon('check') ?> Сохранить роль</button>
        <?php endif; ?>
    </form>
    <form method="post" action="admin.php" class="stack doc-flags">
        <input type="hidden" name="action" value="set_docs"><?= $hidden() ?>
        <fieldset class="choice">
            <legend>Документооборот <span class="badge warn">тест</span></legend>
            <label class="choice-item"><input type="checkbox" name="doc_officer" value="1" <?= (int)($x['doc_officer'] ?? 0) ? 'checked' : '' ?>>
                <span><b>Ответственный за документооборот</b><small>Получает заявки на документы, скачивает заполненные документы и относит на подпись. Может загружать шаблоны.</small></span></label>
            <label class="choice-item"><input type="checkbox" name="doc_tester" value="1" <?= (int)($x['doc_tester'] ?? 0) ? 'checked' : '' ?>>
                <span><b>Тестирует раздел «Документы»</b><small>Видит раздел, пока он в тестовом режиме, и может заказывать документы.</small></span></label>
        </fieldset>
        <button class="btn block"><?= icon('save') ?> Сохранить</button>
    </form>
</div>

<?php endif; ?>

<div class="mpanel" data-panel="access" <?= $tab === 'access' ? '' : 'hidden' ?>>
    <dl class="dl">
        <dt>Логин</dt><dd>@<?= e($x['username']) ?></dd>
        <dt>Почта</dt><dd><?= e($x['email'] ?: '—') ?></dd>
        <dt>Регистрация</dt><dd><?= $x['created_at'] ? e(date('d.m.Y', strtotime((string)$x['created_at']))) : '—' ?></dd>
        <dt>Пароль</dt><dd>
            <?php if ((int)$x['must_change_password'] && !empty($x['temp_password'])): ?>
                временный, ещё не сменён:
                <button type="button" class="code-chip small" data-copy="<?= e($x['temp_password']) ?>" title="Скопировать"><?= e($x['temp_password']) ?></button>
            <?php elseif ((int)$x['must_change_password']): ?>
                выдан временный, ещё не сменён
            <?php else: ?>
                задан пользователем
            <?php endif; ?>
            <?php if ((int)$x['reset_cnt']): ?><span class="badge warn">просит сбросить</span><?php endif; ?>
        </dd>
    </dl>
    <div class="stack">
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="reset_password"><?= $hidden() ?>
            <button class="btn block" data-confirm="Выдать временный пароль для <?= e($x['full_name']) ?>? Старый пароль перестанет работать."><?= icon('key') ?> Выдать временный пароль</button>
        </form>
        <?php if (!$self): ?>
            <form method="post" action="admin.php">
                <input type="hidden" name="action" value="delete_user"><?= $hidden() ?>
                <button class="btn danger-outline block" data-confirm="Удалить учётную запись <?= e($x['full_name']) ?>? Это нельзя отменить. Выставленные оценки сохранятся."><?= icon('trash') ?> Удалить учётную запись</button>
            </form>
        <?php endif; ?>
    </div>
</div>
