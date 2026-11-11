<?php
// Оставлено для старых ссылок: присоединение теперь на главной странице ПИ
require_once 'functions.php';
require_login();
enter_instructor();

if (is_post()) {
    $att = join_attestation($pdo, uid(), (string)($_POST['code'] ?? $_POST['join_code'] ?? ''));
    if ($att) {
        flash('Вы присоединились к аттестации «' . $att['title'] . '»');
        redirect('attestation_instructor.php?id=' . (int)$att['id']);
    }
    flash('Аттестация с таким кодом не найдена', 'err');
}
redirect('index.php');
