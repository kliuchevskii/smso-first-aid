<?php
// Смена пароля по ссылке из письма
require_once 'functions.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$q = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND via = 'email' AND status = 'pending'");
$q->execute([hash('sha256', $token)]);
$req = $q->fetch();
$valid = $req && $token !== '' && (time() - strtotime((string)$req['created_at'])) < 2 * 3600;

if ($valid && is_post()) {
    $p1 = $_POST['password'] ?? '';
    if (mb_strlen($p1) < 6 || $p1 !== ($_POST['password2'] ?? '')) {
        flash('Пароли не совпадают или короче 6 символов', 'err');
        redirect('reset.php?token=' . urlencode($token));
    }
    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, temp_password = NULL WHERE id = ?")
        ->execute([password_hash($p1, PASSWORD_DEFAULT), $req['user_id']]);
    $pdo->prepare("UPDATE password_resets SET status = 'done', done_at = ? WHERE user_id = ? AND status = 'pending'")
        ->execute([date('Y-m-d H:i:s'), $req['user_id']]);
    flash('Пароль изменён. Войдите с новым паролем.');
    redirect('login.php');
}

page_start('Новый пароль', ['width' => 'narrow', 'bare' => true]);
?>
<div class="auth">
    <div class="auth-brand"><span class="brand-mark big">✚</span><div><b>СМСО «Эпсилон»</b></div></div>
    <div class="card">
        <h1>Новый пароль</h1>
        <?php if (!$valid): ?>
            <div class="alert err">Ссылка недействительна или устарела. <a href="forgot.php">Запросите новую</a>.</div>
        <?php else: ?>
            <form method="post" class="stack">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>Новый пароль <input type="password" name="password" minlength="6" required data-autofocus></label>
                <label>Повторите пароль <input type="password" name="password2" minlength="6" required></label>
                <button class="btn primary full">Сохранить пароль</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
