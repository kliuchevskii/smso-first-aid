<?php
$DB_HOST = 'localhost';
$DB_NAME = 'smso';
$DB_USER = 'smso';
$DB_PASS = '';  // настоящие данные храните в config.local.php (он в .gitignore)

// Показывать текст ошибки на странице вместо пустой «HTTP 500».
// После того как всё заработает, можно поставить false.
$SHOW_ERRORS = false;

// Отправка писем для сброса пароля. На бесплатном InfinityFree функция mail() не работает,
// поэтому по умолчанию выключено — сброс идёт через администратора.
// Если хостинг умеет отправлять почту — поставьте true и укажите адрес отправителя.
$MAIL_ENABLED = false;
$MAIL_FROM    = 'no-reply@example.com';

// Реальные доступы к БД и прочие секреты — в config.local.php рядом с этим файлом (не коммитится).
// Пример — config.local.sample.php
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

ini_set('display_errors', $SHOW_ERRORS ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) use ($SHOW_ERRORS) {
    error_log((string)$e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Ошибка</title><link rel="stylesheet" href="styles.css"></head><body>'
       . '<main class="container narrow"><div class="card"><h1>Что-то пошло не так</h1>';
    if ($SHOW_ERRORS) {
        echo '<p class="alert err"><b>' . htmlspecialchars(get_class($e)) . ':</b> '
           . htmlspecialchars($e->getMessage()) . '</p>'
           . '<p class="muted small">' . htmlspecialchars(basename($e->getFile())) . ':' . $e->getLine() . '</p>'
           . '<p>Если ошибка про неизвестный столбец или таблицу — откройте '
           . '<a href="install.php">install.php</a>, он приведёт базу в порядок.</p>';
    } else {
        echo '<p>Попробуйте обновить страницу. Если ошибка повторяется — сообщите сотруднику управления.</p>';
    }
    echo '<p><a class="btn" href="index.php">На главную</a></p></div></main></body></html>';
});

try {
    $pdo = new PDO(
        $DB_DSN ?? "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Ошибка подключения к БД: ' . $e->getMessage());
}

session_start();