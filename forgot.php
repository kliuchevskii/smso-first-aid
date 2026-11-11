<?php
// Восстановление пароля: письмо со ссылкой (если почта настроена) или запрос администратору
require_once 'functions.php';
if (!empty($_SESSION['user_id'])) redirect('profile.php');

$sent = null;
if (is_post()) {
    $login = trim($_POST['login'] ?? '');
    $q = $pdo->prepare("SELECT * FROM users WHERE username = ? OR (email IS NOT NULL AND email <> '' AND LOWER(email) = LOWER(?)) ORDER BY id LIMIT 1");
    $q->execute([$login, $login]);
    $u = $q->fetch();
    $sent = 'admin';

    if ($u) {
        $mailed = false;
        if ($MAIL_ENABLED && !empty($u['email']) && function_exists('mail')) {
            $token = bin2hex(random_bytes(24));
            $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, via, status) VALUES (?, ?, 'email', 'pending')")
                ->execute([$u['id'], hash('sha256', $token)]);
            $link = base_url() . 'reset.php?token=' . $token;
            $body = "Здравствуйте, " . greet_name($u['full_name']) . "!\r\n\r\n"
                  . "Чтобы задать новый пароль на сайте СМСО «Эпсилон», откройте ссылку (действует 2 часа):\r\n$link\r\n\r\n"
                  . "Если вы не запрашивали сброс — просто проигнорируйте письмо.";
            $headers = "From: $MAIL_FROM\r\nContent-Type: text/plain; charset=utf-8";
            $mailed = @mail($u['email'], '=?UTF-8?B?' . base64_encode('Сброс пароля СМСО «Эпсилон»') . '?=', $body, $headers);
            if ($mailed) $sent = 'email';
        }
        if (!$mailed) {
            // не дублируем открытые запросы
            $ex = $pdo->prepare("SELECT 1 FROM password_resets WHERE user_id = ? AND via = 'admin' AND status = 'pending'");
            $ex->execute([$u['id']]);
            if (!$ex->fetchColumn()) {
                $pdo->prepare("INSERT INTO password_resets (user_id, via, status) VALUES (?, 'admin', 'pending')")->execute([$u['id']]);
            }
        }
    }
}

page_start('Восстановление пароля', ['width' => 'narrow', 'bare' => true]);
?>
<div class="auth">
    <div class="auth-brand"><span class="brand-mark big">✚</span><div><b>СМСО «Эпсилон»</b></div></div>
    <div class="card">
        <h1>Восстановление пароля</h1>
        <?php if ($sent === 'email'): ?>
            <div class="alert ok">Мы отправили на вашу почту ссылку для смены пароля. Проверьте папку «Спам», если письма нет.</div>
        <?php elseif ($sent === 'admin'): ?>
            <div class="alert ok">Запрос передан администратору. Он выдаст вам временный пароль — после входа сайт попросит задать новый.</div>
        <?php else: ?>
            <p class="muted">Укажите логин или почту, с которыми вы регистрировались.</p>
            <form method="post" class="stack">
                <label>Логин или почта
                    <input name="login" required data-autofocus>
                </label>
                <button class="btn primary full">Восстановить пароль</button>
            </form>
        <?php endif; ?>
        <p class="center"><a href="login.php">← Ко входу</a></p>
    </div>
</div>
<?php page_end();
