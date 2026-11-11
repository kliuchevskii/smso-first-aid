<?php
// Личные данные и режим работы
require_once 'functions.php';
require_login();

$q = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$q->execute([uid()]);
$me = $q->fetch();
$force = (int)$me['must_change_password'] === 1;

if (is_post()) {
    $action = $_POST['action'] ?? '';
    $ret = $_SERVER['HTTP_REFERER'] ?? 'index.php';

    if ($action === 'save_profile') {
        $fio   = trim(preg_replace('/\s+/u', ' ', $_POST['full_name'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        if (count(preg_split('/\s+/u', $fio)) < 2) {
            flash('Укажите фамилию, имя и отчество', 'err');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Проверьте адрес почты', 'err');
        } else {
            $d = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?");
            $d->execute([$email, uid()]);
            if ($email !== '' && $d->fetchColumn()) {
                flash('Эта почта уже используется другой учётной записью', 'err');
            } else {
                $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?")->execute([$fio, $email ?: null, uid()]);
                $_SESSION['full_name'] = $fio;
                flash('Данные сохранены');
            }
        }
        redirect('profile.php');
    }

    if ($action === 'change_password') {
        $cur = $_POST['current'] ?? '';
        $p1  = $_POST['password'] ?? '';
        if (!$force && !password_verify($cur, $me['password_hash'])) {
            flash('Текущий пароль указан неверно', 'err');
        } elseif (mb_strlen($p1) < 6) {
            flash('Новый пароль должен быть не короче 6 символов', 'err');
        } elseif ($p1 !== ($_POST['password2'] ?? '')) {
            flash('Пароли не совпадают', 'err');
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, temp_password = NULL WHERE id = ?")
                ->execute([password_hash($p1, PASSWORD_DEFAULT), uid()]);
            flash('Пароль изменён');
            redirect($force ? 'index.php' : 'profile.php');
        }
        redirect('profile.php?tab=password' . ($force ? '&force=1' : ''));
    }

    if ($action === 'become_manager' && !has_manager_rights()) {
        $role = use_role_code($pdo, (string)($_POST['code'] ?? ''), uid());
        if ($role === 'admin') {
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([uid()]);
            notify_role($pdo, uid(), 'admin');
            $_SESSION['role'] = 'admin';
            unset($_SESSION['mode']);
            flash('Полномочия подтверждены — теперь вы сотрудник управления. Раздел «Управление» появился в меню, код больше не понадобится.');
            redirect('manage.php');
        }
        flash('Код не подошёл или уже использован. Личный код выдаёт администратор.', 'err');
        redirect('profile.php?tab=rights');
    }
    redirect('profile.php');
}

$tabs = ['main' => 'Данные', 'password' => 'Пароль'];
if (!has_manager_rights()) $tabs['rights'] = 'Полномочия';
$tabs['app'] = 'Приложение';
$tab = $_GET['tab'] ?? 'main';
if ($force) $tab = 'password';
if (!isset($tabs[$tab])) $tab = 'main';

// цифры в карточке профиля
$a = $pdo->prepare("SELECT COUNT(*) FROM attestation_members WHERE user_id = ? AND status = 'active'");
$a->execute([uid()]);
$stats = [[(int)$a->fetchColumn(), ['аттестация', 'аттестации', 'аттестаций']]];
if (has_manager_rights()) {
    $m = $pdo->prepare("SELECT COUNT(*) FROM attestations WHERE created_by = ?");
    $m->execute([uid()]);
    $stats = [[$stats[0][0], 'как инструктор'], [(int)$m->fetchColumn(), 'ведёт']];
}

page_start('Профиль', ['width' => 'narrow', 'section' => 'profile']);
?>
<div class="card profile-head">
    <span class="avatar avatar-lg"><?= e(initials($me['full_name'])) ?></span>
    <div class="ph-main">
        <h1><?= e($me['full_name']) ?></h1>
        <p class="meta">@<?= e($me['username']) ?><?= $me['email'] ? ' · ' . e($me['email']) : '' ?></p>
        <div class="ph-badges">
            <span class="badge <?= ['sysadmin' => 'gold', 'admin' => 'info'][$me['role']] ?? 'brand' ?>"><?= e(role_name($me['role'])) ?></span>
        </div>
    </div>
    <div class="ph-stats">
        <?php foreach ($stats as [$n, $w]): ?>
            <div class="ph-stat"><b><?= $n ?></b><span><?= e(is_array($w) ? preg_replace('/^\d+\s*/u', '', plural($n, ...$w)) : $w) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$force && has_manager_rights()): ?>
<div class="quick mob-only">
    <?php if (has_manager_rights()): ?><a class="btn block" href="manage.php"><?= icon('shield') ?> Управление аттестациями</a><?php endif; ?>
    <?php if (is_sysadmin()): ?><a class="btn block" href="admin.php"><?= icon('key') ?> Администрирование</a><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$force): ?>
<nav class="seg-tabs">
    <?php foreach ($tabs as $k => $label): ?>
        <a class="<?= $tab === $k ? 'is-on' : '' ?>" href="profile.php<?= $k === 'main' ? '' : '?tab=' . $k ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($tab === 'main'): ?>
<div class="card">
    <form method="post" class="stack">
        <input type="hidden" name="action" value="save_profile">
        <label>Фамилия Имя Отчество
            <input name="full_name" value="<?= e($me['full_name']) ?>" required>
        </label>
        <label>Почта
            <input type="email" name="email" value="<?= e($me['email'] ?? '') ?>" placeholder="name@example.ru">
        </label>
        <div class="grid-2">
            <div class="ro-field"><span>Логин для входа</span><b><?= e($me['username']) ?></b></div>
            <div class="ro-field"><span>Роль на платформе</span><b><?= e(role_name($me['role'])) ?></b></div>
        </div>
        <div><button class="btn primary"><?= icon('save') ?> Сохранить</button></div>
    </form>
</div>

<?php elseif ($tab === 'password'): ?>
<div class="card" id="password">
    <h2 class="mt0"><?= $force ? 'Задайте новый пароль' : 'Смена пароля' ?></h2>
    <?php if ($force): ?><p class="muted">Вы вошли с временным паролем, выданным администратором. Придумайте свой.</p><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="action" value="change_password">
        <?php if (!$force): ?>
            <label>Текущий пароль <input type="password" name="current" autocomplete="current-password" required></label>
        <?php endif; ?>
        <label>Новый пароль <input type="password" name="password" autocomplete="new-password" minlength="6" required></label>
        <label>Повторите новый пароль <input type="password" name="password2" autocomplete="new-password" minlength="6" required></label>
        <div><button class="btn primary"><?= icon('key') ?> Сменить пароль</button></div>
    </form>
</div>

<?php elseif ($tab === 'rights'): ?>
<div class="card" id="rights">
    <h2 class="mt0">Полномочия сотрудника управления</h2>
    <p class="muted">Сотрудник управления создаёт аттестации и ведёт их в разделе «Управление», а в разделе «Аттестации» по-прежнему работает как инструктор. Введите личный код, выданный администратором, — после подтверждения код больше не потребуется.</p>
    <form method="post" class="inline-form">
        <input type="hidden" name="action" value="become_manager">
        <input name="code" class="code-input" placeholder="КОД" autocomplete="off" required>
        <button class="btn primary"><?= icon('shield') ?> Подтвердить</button>
    </form>
</div>

<?php else: ?>
<div class="stack">
    <?php install_card(); ?>
    <?php push_card('profile.php?tab=app'); ?>
</div>
<?php endif; ?>

<?php if (!$force): ?>
<a class="btn danger-outline block logout-row" href="logout.php"><?= icon('logout') ?> Выйти из аккаунта</a>
<?php endif; ?>
<?php page_end();
