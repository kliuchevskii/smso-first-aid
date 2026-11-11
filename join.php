<?php
// Приглашение по QR-коду / ссылке: join.php?code=КОД
// Неавторизованного отправляет на вход (или регистрацию) и возвращает сюда.
require_once 'functions.php';

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if (empty($_SESSION['user_id'])) {
    $_SESSION['after_login'] = 'join.php?code=' . urlencode($code);
    flash('Войдите, чтобы присоединиться к аттестации', 'info');
    redirect('login.php');
}
require_login();

$q = $pdo->prepare("SELECT * FROM attestations WHERE join_code = ? AND status <> 'prep'");
$q->execute([$code]);
$att = $q->fetch();
if (!$att) {
    flash('Приглашение недействительно: аттестация не найдена', 'err');
    redirect('index.php');
}
enter_instructor();

join_attestation($pdo, uid(), $code);
flash('Вы присоединились к аттестации «' . $att['title'] . '»');
redirect('attestation_instructor.php?id=' . (int)$att['id']);
