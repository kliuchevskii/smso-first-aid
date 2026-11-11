<?php
require_once 'functions.php';

if (!empty($_SESSION['user_id'])) redirect(after_login_url());

$username = '';
if (is_post()) {
    $username = trim($_POST['username'] ?? '');
    // вход по логину или почте
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR (email IS NOT NULL AND email <> '' AND LOWER(email) = LOWER(?)) ORDER BY id LIMIT 1");
    $stmt->execute([$username, $username]);
    $u = $stmt->fetch();

    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        login_user($u);
        redirect(after_login_url());
    }
    flash('Неверный логин или пароль', 'err');
    redirect('login.php');
}

$inviting = str_contains((string)($_SESSION['after_login'] ?? ''), 'join.php');
page_start('Вход', ['width' => 'narrow', 'bare' => true]);
?>
<div class="auth">
    <div class="auth-brand"><span class="brand-mark big">✚</span>
        <div><b>СМСО «Эпсилон»</b><div class="muted small">Аттестация по первой помощи<?= site_info()['site_org'] !== '' ? ' · ' . e(site_info()['site_org']) : '' ?></div></div>
    </div>
    <div class="card">
        <h1>Вход для инструкторов</h1>
        <p class="muted small mt0">Для инструкторов и сотрудников управления. Аттестуемым — кнопка «Я аттестуемый» ниже.</p>
        <?php if ($inviting): ?>
            <div class="alert info">Войдите или зарегистрируйтесь — после этого вы автоматически присоединитесь к аттестации.</div>
        <?php endif; ?>
        <form method="post" class="stack">
            <label>Логин или почта
                <input name="username" value="<?= e($username) ?>" autocomplete="username" required data-autofocus>
            </label>
            <label>Пароль
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="btn primary full">Войти</button>
        </form>
        <p class="center"><a href="forgot.php">Забыли пароль?</a></p>
        <p class="center muted">Нет аккаунта? <a href="register.php">Зарегистрироваться</a></p>
        <a class="btn full participant-entry" href="reg.php"><?= icon('user') ?> Я аттестуемый</a>
    </div>
</div>
<?php page_end();
