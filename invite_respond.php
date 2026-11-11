<?php
// Инструктор принимает или отклоняет приглашение в аттестацию
require_once 'functions.php';
require_login();

$attId  = (int)($_POST['att'] ?? 0);
$answer = $_POST['answer'] ?? '';
$back   = (string)($_POST['back'] ?? '');
if ($back === '' || preg_match('~^(https?:)?//~i', $back)) $back = 'index.php';

$att = get_attestation($pdo, $attId);
if (!is_post() || !$att || member_status($pdo, $attId, uid()) !== 'invited') {
    flash('Приглашение уже недействительно', 'warn');
    redirect('index.php');
}

if ($answer === 'accept') {
    $pdo->prepare("UPDATE attestation_members SET status = 'active' WHERE attestation_id = ? AND user_id = ?")->execute([$attId, uid()]);
    flash("Вы приняли приглашение в аттестацию «{$att['title']}»");
    redirect("attestation_instructor.php?id=$attId");
}

$pdo->prepare("DELETE FROM attestation_members WHERE attestation_id = ? AND user_id = ? AND status = 'invited'")->execute([$attId, uid()]);
if (!empty($att['created_by'])) {
    notify($pdo, (int)$att['created_by'], ($_SESSION['full_name'] ?? 'Инструктор') . " отклонил(а) приглашение в аттестацию «{$att['title']}»",
        "attestation.php?id=$attId#instructors", 'info');
}
flash('Приглашение отклонено', 'warn');
redirect($back);
