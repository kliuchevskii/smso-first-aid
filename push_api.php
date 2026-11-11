<?php
/* Подписка устройства на push-уведомления (вызывается из app.js). Всегда отвечает JSON. */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo json_encode(['ok' => false, 'error' => 'Ошибка PHP: ' . $e['message']], JSON_UNESCAPED_UNICODE);
    }
});
require_once __DIR__ . '/functions.php';
ini_set('display_errors', '0');
set_exception_handler(null);
@set_time_limit(30);

function out(array $a): never { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

try {
    if (empty($_SESSION['user_id'])) out(['ok' => false, 'error' => 'Нужно войти заново']);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['push_token'] ?? '', (string)($in['token'] ?? ''))) out(['ok' => false, 'error' => 'Обновите страницу']);
    $uid = uid();
    $sub = (array)($in['sub'] ?? []);
    $endpoint = (string)($sub['endpoint'] ?? '');
    $now = date('Y-m-d H:i:s');

    switch ($in['a'] ?? '') {
    case 'sub':
        $p256 = (string)($sub['keys']['p256dh'] ?? '');
        $auth = (string)($sub['keys']['auth'] ?? '');
        if (!preg_match('~^https://~', $endpoint) || strlen(b64u_dec($p256)) !== 65 || strlen(b64u_dec($auth)) !== 16) {
            out(['ok' => false, 'error' => 'Неверная подписка']);
        }
        $h = hash('sha256', $endpoint);
        $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ?")->execute([$h]);
        $pdo->prepare("INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, ua, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$uid, $h, $endpoint, $p256, $auth, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), $now]);
        out(['ok' => true]);
    case 'unsub':
        $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?")->execute([hash('sha256', $endpoint), $uid]);
        out(['ok' => true]);
    case 'test':
        $q = $pdo->prepare("SELECT * FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?");
        $q->execute([hash('sha256', $endpoint), $uid]);
        $s = $q->fetch();
        if (!$s) out(['ok' => false, 'error' => 'Это устройство не подписано — выключите и включите уведомления заново']);
        $res = webpush_send_many([[$s, [
            'title' => 'СМСО «Эпсилон»',
            'body'  => 'Уведомления работают. Так будут приходить приглашения в аттестации и другие события.',
            'url'   => push_base() . 'notifications.php',
            'tag'   => 'test',
        ]]]);
        $code = $res[(int)$s['id']] ?? 0;
        if ($code >= 200 && $code < 300) out(['ok' => true, 'code' => $code]);
        out(['ok' => false, 'code' => $code,
             'error' => $code === 0 ? 'хостинг не смог отправить запрос (' . ($GLOBALS['PUSH_LAST_ERROR'] ?? '') . ')' : "сервис уведомлений ответил кодом $code",
             'diag' => webpush_diag()]);
    case 'diag':
        out(['ok' => true, 'diag' => webpush_diag()]);
    default:
        out(['ok' => false, 'error' => 'Неизвестное действие']);
    }
} catch (Throwable $e) {
    out(['ok' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage(), 'diag' => function_exists('webpush_diag') ? webpush_diag() : null]);
}
