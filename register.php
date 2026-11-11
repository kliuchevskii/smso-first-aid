<?php
require_once 'functions.php';

if (!empty($_SESSION['user_id'])) redirect(after_login_url());

$v = ['username' => '', 'full_name' => '', 'email' => '', 'code' => ''];
if (is_post()) {
    $v['username']  = trim($_POST['username'] ?? '');
    $v['full_name'] = trim(preg_replace('/\s+/u', ' ', $_POST['full_name'] ?? ''));
    $v['email']     = trim($_POST['email'] ?? '');
    $v['code']      = trim($_POST['code'] ?? '');
    $password       = $_POST['password'] ?? '';
    $err = null;

    if ($v['username'] === '' || $password === '' || $v['full_name'] === '' || $v['email'] === '') {
        $err = 'Заполните все поля';
    } elseif (count(preg_split('/\s+/u', $v['full_name'])) < 2) {
        $err = 'Укажите фамилию, имя и отчество';
    } elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'Проверьте адрес почты';
    } elseif (mb_strlen($password) < 6) {
        $err = 'Пароль должен быть не короче 6 символов';
    } elseif ($v['code'] !== '' && $v['code'] !== SYSADMIN_CODE && !role_code_valid($pdo, $v['code'])) {
        $err = 'Код доступа не найден или уже использован. Оставьте поле пустым, чтобы зарегистрироваться инструктором.';
    } else {
        $chk = $pdo->prepare("SELECT username, email FROM users WHERE username = ? OR LOWER(email) = LOWER(?)");
        $chk->execute([$v['username'], $v['email']]);
        $dup = $chk->fetch();
        if ($dup) {
            $err = $dup['username'] === $v['username'] ? 'Этот логин уже занят' : 'Эта почта уже зарегистрирована';
        }
    }

    if ($err) {
        flash($err, 'err');
    } else {
        $role = $v['code'] === SYSADMIN_CODE ? 'sysadmin' : 'instructor';
        $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, role) VALUES (?, ?, ?, ?, ?)")
            ->execute([$v['username'], password_hash($password, PASSWORD_DEFAULT), $v['full_name'], $v['email'], $role]);
        $id = (int)$pdo->lastInsertId();
        if ($v['code'] !== '' && $role !== 'sysadmin') {
            $granted = use_role_code($pdo, $v['code'], $id);
            if ($granted) {
                $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$granted, $id]);
                $role = $granted;
                notify_role($pdo, $id, $granted);
            }
        }
        login_user(['id' => $id, 'role' => $role, 'full_name' => $v['full_name']]);
        flash('Аккаунт создан: ' . mb_strtolower(role_name($role)) . '. Добро пожаловать!');
        redirect(after_login_url());
    }
}

page_start('Регистрация', ['width' => 'narrow', 'bare' => true]);
?>
<div class="auth">
    <div class="auth-brand"><span class="brand-mark big">✚</span>
        <div><b>СМСО «Эпсилон»</b></div>
    </div>
    <div class="card">
        <h1>Регистрация</h1>
        <form method="post" class="stack">
            <label>Фамилия Имя Отчество
                <input name="full_name" value="<?= e($v['full_name']) ?>" placeholder="Иванов Иван Иванович" autocomplete="name" required data-autofocus>
            </label>
            <label>Почта
                <input type="email" name="email" value="<?= e($v['email']) ?>" placeholder="name@example.ru" autocomplete="email" required>
            </label>
            <label>Логин
                <input name="username" value="<?= e($v['username']) ?>" autocomplete="username" required>
            </label>
            <label>Пароль
                <input type="password" name="password" autocomplete="new-password" required minlength="6">
            </label>
            <div class="code-reveal">
                <button type="button" class="link-btn" data-toggle="#code-field" data-focus="#code-input">У меня есть код доступа</button>
                <div id="code-field" <?= $v['code'] !== '' ? '' : 'hidden' ?>>
                    <label>Код доступа
                        <input id="code-input" name="code" value="<?= e($v['code']) ?>" autocomplete="off" class="code-input" placeholder="КОД">
                    </label>
                    <p class="muted small">Код выдаёт администратор — с ним вы сразу получите права сотрудника управления.</p>
                </div>
            </div>
            <p class="muted small consent">Нажимая «Зарегистрироваться», вы соглашаетесь с обработкой данных для проведения аттестации — <a href="privacy.php">политика конфиденциальности</a>.</p>
            <button class="btn primary full">Зарегистрироваться</button>
        </form>
        <p class="center muted">Уже есть аккаунт? <a href="login.php">Войти</a></p>
    </div>
</div>
<?php page_end();
