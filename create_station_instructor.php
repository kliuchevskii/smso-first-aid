<?php
require_once 'functions.php';
require_login();
enter_instructor();   // сотрудник управления здесь работает как инструктор

$attId = (int)($_POST['attestation'] ?? 0);
$name  = trim($_POST['station_name'] ?? '');
$att   = get_attestation($pdo, $attId);
$back  = "attestation_instructor.php?id=$attId";

if (!is_post() || !$att || !is_member($pdo, $attId, uid())) {
    redirect('index.php');
}
if ($att['status'] !== 'draft') {
    flash('Аттестация уже завершена', 'err');
    redirect($back);
}
if (att_started($pdo, $attId)) {
    flash(STARTED_MSG, 'err');
    redirect($back);
}
if (!(int)$att['allow_own_stations']) {
    flash('В этой аттестации нельзя создавать свои станции — выберите станцию сотрудника управления', 'err');
    redirect($back);
}
if ((int)$att['one_judge_per_station'] && my_stations($pdo, uid(), $attId)) {
    flash('В этой аттестации у инструктора может быть только одна станция', 'err');
    redirect($back);
}
if ($name === '') {
    flash('Укажите название станции', 'err');
    redirect($back);
}

$pdo->prepare("INSERT INTO stations (attestation_id, name, created_by, created_by_admin, approved, pass_threshold) VALUES (?, ?, ?, 0, 0, ?)")
    ->execute([$attId, $name, uid(), PASS_DEFAULT]);
$sid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO station_judges (station_id, user_id, approved) VALUES (?, ?, 0)")
    ->execute([$sid, uid()]);

redirect("criteria_edit.php?station=$sid&wizard=1&add=1");
