<?php
// Сохранение отметок урока (вызывается из lesson.php без перезагрузки страницы)
require_once 'functions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$lid  = (int)($_POST['lesson'] ?? 0);
$item = (int)($_POST['item'] ?? 0);
$sec  = max(0, (int)($_POST['section'] ?? 0));

$q = $pdo->prepare("SELECT l.id, l.course_id FROM lessons l WHERE l.id = ?");
$q->execute([$lid]);
$lesson = $q->fetch();
$course = $lesson ? get_course($pdo, (int)$lesson['course_id']) : null;
if (!is_post() || !$course || !course_can_view($course)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

if ($item) {
    $chk = $pdo->prepare("SELECT 1 FROM lesson_items i JOIN lesson_sections s ON s.id = i.section_id WHERE i.id = ? AND s.lesson_id = ?");
    $chk->execute([$item, $lid]);
    if ($chk->fetchColumn()) {
        $pdo->prepare("DELETE FROM lesson_progress WHERE user_id = ? AND item_id = ?")->execute([uid(), $item]);
        if (!empty($_POST['done'])) {
            $pdo->prepare("INSERT INTO lesson_progress (user_id, lesson_id, item_id) VALUES (?, ?, ?)")->execute([uid(), $lid, $item]);
        }
    }
}

// запоминаем подраздел, на котором остановились
$ex = $pdo->prepare("SELECT 1 FROM lesson_done WHERE user_id = ? AND lesson_id = ?");
$ex->execute([uid(), $lid]);
if ($ex->fetchColumn()) {
    $pdo->prepare("UPDATE lesson_done SET last_section = ? WHERE user_id = ? AND lesson_id = ?")->execute([$sec, uid(), $lid]);
} else {
    $pdo->prepare("INSERT INTO lesson_done (user_id, lesson_id, last_section) VALUES (?, ?, ?)")->execute([uid(), $lid, $sec]);
}

$c = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND lesson_id = ?");
$c->execute([uid(), $lid]);
echo json_encode(['ok' => true, 'done' => (int)$c->fetchColumn()]);
