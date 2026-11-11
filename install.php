<?php
// Проверка и обновление структуры БД. Безопасно запускать повторно:
// только добавляет недостающее, ничего не удаляет.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';

$log = migrate_schema($pdo);
@file_put_contents(schema_marker_file(), (string)SCHEMA_VERSION);
$_SESSION['schema_ok'] = SCHEMA_VERSION;

$db = $pdo->query("SELECT DATABASE()")->fetchColumn();
$cols = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION");
$cols->execute([$db]);
$byTable = [];
foreach ($cols->fetchAll() as $c) {
    $byTable[$c['TABLE_NAME']][] = $c['COLUMN_NAME'] . ' <span class="muted">' . htmlspecialchars($c['COLUMN_TYPE']) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Проверка базы · СМСО</title>
    <link rel="stylesheet" href="styles.css?v=13">
</head>
<body>
<main class="container narrow">
    <div class="card">
        <h1>Проверка базы данных</h1>
        <?php foreach ($log as [$t, $m]): ?>
            <div class="alert <?= $t ?>"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>
        <details class="spoiler">
            <summary>Текущая структура таблиц</summary>
            <?php foreach ($byTable as $t => $list): ?>
                <p><b><?= htmlspecialchars($t) ?></b>: <?= implode(', ', $list) ?></p>
            <?php endforeach; ?>
        </details>
        <p><a class="btn primary" href="index.php">Перейти на сайт</a></p>
    </div>
</main>
</body>
</html>
