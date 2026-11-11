<?php
require_once 'functions.php';
require_login();
enter_instructor();   // сотрудник управления здесь работает как инструктор

$sid   = (int)($_POST['station'] ?? 0);
$attId = (int)($_POST['attestation'] ?? 0);

if (is_post() && $sid) {
    $chk = $pdo->prepare("
        SELECT s.attestation_id, a.status, a.one_judge_per_station FROM stations s
        JOIN attestations a ON a.id = s.attestation_id
        JOIN attestation_members m ON m.attestation_id = s.attestation_id
        WHERE s.id = ? AND m.user_id = ? AND s.created_by_admin = 1
    ");
    $chk->execute([$sid, uid()]);
    $row = $chk->fetch();

    if ($row && $row['status'] === 'draft' && (int)$row['one_judge_per_station']) {
        $busy = $pdo->prepare("SELECT COUNT(*) FROM station_judges WHERE station_id = ? AND user_id <> ?");
        $busy->execute([$sid, uid()]);
        if ((int)$busy->fetchColumn() > 0) {
            flash('На этой станции уже есть инструктор', 'err');
            redirect("attestation_instructor.php?id=$attId");
        }
        if (my_stations($pdo, uid(), (int)$row['attestation_id'])) {
            flash('В этой аттестации у инструктора может быть только одна станция', 'err');
            redirect("attestation_instructor.php?id=$attId");
        }
    }
    if ($row && $row['status'] === 'draft') {
        $ex = $pdo->prepare("SELECT 1 FROM station_judges WHERE station_id = ? AND user_id = ?");
        $ex->execute([$sid, uid()]);
        if (!$ex->fetchColumn()) {
            $pdo->prepare("INSERT INTO station_judges (station_id, user_id, approved) VALUES (?, ?, 0)")
                ->execute([$sid, uid()]);
        }
        flash('Станция выбрана. Ждём подтверждения сотрудника управления — пока можно проверить критерии.');
        redirect("station_instructor.php?id=$sid");
    }
    flash('Эту станцию сейчас нельзя выбрать', 'err');
}

redirect($attId ? "attestation_instructor.php?id=$attId" : 'index.php');
