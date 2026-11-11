<?php
require_once __DIR__ . '/config.php';
// предупреждения об устаревших функциях PHP не должны попадать в страницы и файлы выгрузки
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/webpush.php';
ensure_schema($pdo);
force_https_if_enabled();

const SCORE_OPTIONS = ['0', '0.25', '0.5', '0.75', '1'];

/* ================= Доступ и роли ================= */
/*
 * Роли пользователя (users.role):
 *   sysadmin   — администратор: всё, что сотрудник управления, плюс раздел «Администрирование»;
 *   admin      — сотрудник управления (ГИ): раздел «Управление», а в «Аттестациях» — как инструктор;
 *   instructor — инструктор (ПИ);
 *   participant — аттестуемый, зарегистрировавшийся сам: временная учётная запись,
 *                 привязана к одной аттестации (users.attestation_id) и удаляется вместе с ней.
 */
const SYSADMIN_CODE = '03456';   // код регистрации администратора

function require_login(): void {
    global $pdo;
    if (empty($_SESSION['user_id'])) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        }
        redirect('login.php');
    }
    // Роль могли поменять или учётную запись удалить — сверяемся с базой на каждом запросе
    $q = $pdo->prepare("SELECT id, role, full_name, must_change_password FROM users WHERE id = ?");
    $q->execute([uid()]);
    $u = $q->fetch();
    if (!$u) {
        $_SESSION = [];
        flash('Учётная запись не найдена — войдите заново', 'err');
        redirect('login.php');
    }
    $_SESSION['role'] = $u['role'];
    $_SESSION['full_name'] = $u['full_name'];
    if (!in_array($u['role'], ['admin', 'sysadmin'], true)) unset($_SESSION['mode']);

    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    // аттестуемому доступна только его страница
    if ($u['role'] === 'participant' && !in_array($page, ['me.php', 'logout.php'], true)) {
        redirect('me.php');
    }
    // аттестуемый меняет временный пароль на своей странице (me.php)
    if ((int)$u['must_change_password'] && $u['role'] !== 'participant' && !in_array($page, ['profile.php', 'logout.php'], true)) {
        flash('Задайте новый пароль вместо временного', 'warn');
        redirect('profile.php?force=1');
    }
}

/** Роль, в которой человек сейчас работает (с учётом режима инструктора у ГИ) */
function eff_role(): string {
    $r = $_SESSION['role'] ?? '';
    // администратор умеет всё, что сотрудник управления (как в itmo.sail)
    if ($r === 'admin' || $r === 'sysadmin') return ($_SESSION['mode'] ?? '') === 'instructor' ? 'instructor' : 'admin';
    return $r;
}

/*
 * Отдельного переключателя режимов нет (как в itmo.sail): сотрудник управления в разделе
 * «Аттестации» работает как инструктор, а в разделе «Управление» — как управленец.
 * Контекст задаёт сама страница: страницы управления вызывают require_role('admin'),
 * страницы инструктора — enter_instructor(). Общие страницы (станция, критерии, порог)
 * работают в том контексте, из которого человек на них пришёл.
 */
function enter_instructor(): void {
    if (has_manager_rights()) $_SESSION['mode'] = 'instructor';
}
function enter_manage(): void {
    if (has_manager_rights()) unset($_SESSION['mode']);
}

/**
 * Общие страницы станции (название, критерии, порог) у сотрудника управления:
 * если он не инструктор этой аттестации — это точно работа из раздела «Управление».
 * Если состоит в ней инструктором — остаётся контекст раздела, из которого он пришёл.
 */
function station_context(PDO $pdo, int $sid): void {
    if (!has_manager_rights() || !$sid) return;
    $q = $pdo->prepare("SELECT attestation_id FROM stations WHERE id = ?");
    $q->execute([$sid]);
    $att = (int)$q->fetchColumn();
    if ($att && !is_member($pdo, $att, uid())) enter_manage();
}

function require_role(string $role): void {
    require_login();
    if ($role === 'admin') enter_manage();
    if (eff_role() !== $role) {
        flash($role === 'admin' ? 'Раздел «Управление» доступен только сотрудникам управления' : 'Эта страница вам недоступна', 'err');
        redirect('index.php');
    }
}

/** ГИ в режиме управления */
function is_admin(): bool {
    return eff_role() === 'admin';
}

/** Есть полномочия сотрудника управления (в любом режиме) */
function has_manager_rights(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'sysadmin'], true);
}

function is_participant(): bool {
    return ($_SESSION['role'] ?? '') === 'participant';
}

function is_sysadmin(): bool {
    return ($_SESSION['role'] ?? '') === 'sysadmin';
}

function role_name(string $role): string {
    return ['sysadmin' => 'Администратор', 'admin' => 'Сотрудник управления', 'instructor' => 'Инструктор', 'participant' => 'Аттестуемый'][$role] ?? $role;
}

/** Имя и отчество из ФИО «Фамилия Имя Отчество» */
function greet_name(string $fio): string {
    $parts = preg_split('/\s+/u', trim($fio));
    return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : ($parts[0] ?? '');
}

/** Полный адрес сайта (для QR-кода) */
function base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/** Проверить код роли (ГИ) и, если подходит, засчитать использование. Возвращает роль или null. */
function use_role_code(PDO $pdo, string $code, int $userId): ?string {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $q = $pdo->prepare("SELECT * FROM role_codes WHERE code = ?");
    $q->execute([$code]);
    $c = $q->fetch();
    if (!$c || ((int)$c['max_uses'] > 0 && (int)$c['used_count'] >= (int)$c['max_uses'])) return null;
    $pdo->prepare("UPDATE role_codes SET used_count = used_count + 1 WHERE id = ?")->execute([$c['id']]);
    $pdo->prepare("INSERT INTO role_code_uses (code_id, user_id) VALUES (?, ?)")->execute([$c['id'], $userId]);
    return $c['role'];
}

/** Проверить код без использования */
function role_code_valid(PDO $pdo, string $code): bool {
    $code = strtoupper(trim($code));
    if ($code === '') return false;
    $q = $pdo->prepare("SELECT max_uses, used_count FROM role_codes WHERE code = ?");
    $q->execute([$code]);
    $c = $q->fetch();
    return $c && ((int)$c['max_uses'] === 0 || (int)$c['used_count'] < (int)$c['max_uses']);
}

/** Войти под пользователем */
function login_user(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$u['id'];
    $_SESSION['role']      = $u['role'];
    $_SESSION['full_name'] = $u['full_name'];
    unset($_SESSION['mode']);
}

/** Куда вести после входа (например, приглашение по QR) */
function after_login_url(): string {
    $u = $_SESSION['after_login'] ?? '';
    unset($_SESSION['after_login']);
    if ($u && !preg_match('~^(https?:)?//~i', $u) && !str_contains($u, 'login.php') && !str_contains($u, 'register.php')) {
        return $u;
    }
    return ($_SESSION['role'] ?? '') === 'participant' ? 'me.php' : 'index.php';
}

/* ================= Иконки (линейные SVG) ================= */

function icon(string $name, string $cls = ''): string {
    $p = [
        'list'     => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v15H6a2 2 0 0 0-2 2z"/><path d="M4 20a2 2 0 0 0 2 2h13v-4"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>',
        'gear'     => '<path d="M19.38 10.17 L21.89 10.51 L21.89 13.49 L19.38 13.83 L18.51 15.92 L20.04 17.94 L17.94 20.04 L15.92 18.51 L13.83 19.38 L13.49 21.89 L10.51 21.89 L10.17 19.38 L8.08 18.51 L6.06 20.04 L3.96 17.94 L5.49 15.92 L4.62 13.83 L2.11 13.49 L2.11 10.51 L4.62 10.17 L5.49 8.08 L3.96 6.06 L6.06 3.96 L8.08 5.49 L10.17 4.62 L10.51 2.11 L13.49 2.11 L13.83 4.62 L15.92 5.49 L17.94 3.96 L20.04 6.06 L18.51 8.08Z"/><circle cx="12" cy="12" r="3"/>',
        'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
        'trash'    => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'chart'    => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
        'qr'       => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M17 21h4v-4M14 18v3"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'x'        => '<path d="M6 6l12 12M18 6L6 18"/>',
        'left'     => '<path d="M15 5l-7 7 7 7"/>',
        'phone'    => '<rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
        'up'       => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'down'     => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'logout'   => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'swap'     => '<path d="M4 8h13l-3-3M20 16H7l3 3"/>',
        'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'send'     => '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4z"/>',
        'save'     => '<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v5h7V3M8 21v-7h8v7"/>',
        'check'    => '<path d="M5 12l5 5 9-10"/>',
        'bell'     => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'play'     => '<circle cx="12" cy="12" r="9"/><path d="M10 8l6 4-6 4z"/>',
        'more'     => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
        'eye'      => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    ][$name] ?? '';
    return '<svg class="ico ' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function uid(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function is_post(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/* ================= Вывод ================= */

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** flash('текст') — сохранить, flash() — забрать ['m' => текст, 't' => ok|err|warn] */
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) {
        $_SESSION['flash'] = ['m' => $msg, 't' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (is_string($f)) {
        $f = ['m' => $f, 't' => 'ok'];
    }
    return $f;
}

/** 0.50 → «0.5», 1.00 → «1» */
function fmt_score($v): string {
    if ($v === null || $v === '') return '—';
    $s = rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

function status_label(string $s): string {
    return [
        'prep'      => 'Черновик',
        'draft'     => 'Идёт',
        'finished'  => 'Завершена',
        'confirmed' => 'Результаты подтверждены',
    ][$s] ?? $s;
}

function status_class(string $s): string {
    return ['prep' => '', 'draft' => 'info', 'finished' => 'warn', 'confirmed' => 'ok'][$s] ?? '';
}

/** Класс подсветки ячейки результата по доле от максимума */
function ratio_class(float $got, float $max): string {
    if ($max <= 0) return '';
    $r = $got / $max;
    if ($r >= 0.8) return 'good';
    if ($r >= 0.5) return 'mid';
    return 'low';
}

function plural(int $n, string $one, string $few, string $many): string {
    $n10 = $n % 10; $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11) return "$n $one";
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20)) return "$n $few";
    return "$n $many";
}

function role_label(): string {
    if (is_participant()) return 'Аттестуемый';
    if (is_sysadmin()) return 'Администратор';
    return has_manager_rights() ? 'Сотрудник управления' : 'Инструктор';
}

/** Инициалы для аватара: «Иванов Иван Иванович» → «ИИ» */
function initials(string $fio): string {
    $w = preg_split('/\s+/u', trim($fio)) ?: [];
    $r = '';
    foreach (array_slice($w, 0, 2) as $x) $r .= mb_strtoupper(mb_substr($x, 0, 1));
    return $r !== '' ? $r : '?';
}

/** Короткое имя для шапки: «Иванов Иван Иванович» → «Иванов И. И.» */
function short_name(string $fio): string {
    $w = preg_split('/\s+/u', trim($fio)) ?: [];
    if (!$w || $w[0] === '') return '';
    $r = $w[0];
    foreach (array_slice($w, 1, 2) as $x) $r .= ' ' . mb_substr($x, 0, 1) . '.';
    return $r;
}

/** Пункты меню: [ссылка, иконка, подпись, ключ раздела, короткая подпись для нижней панели] */
function nav_items(): array {
    if (is_participant()) return [['me.php', 'list', 'Моя аттестация', 'me', 'Аттестация']];
    $m = [['index.php', 'list', 'Аттестации', 'att', 'Аттестации']];
    $m[] = ['courses.php', 'book', 'Курсы', 'courses', 'Курсы'];
    if (docs_can_use()) $m[] = ['docs.php', 'file', 'Документы', 'docs', 'Документы'];
    $m[] = ['notifications.php', 'bell', 'Уведомления', 'notifications', 'Уведомления'];
    $m[] = ['profile.php', 'user', 'Профиль', 'profile', 'Профиль'];
    // как в itmo.sail: разделы управления и администрирования — отдельными пунктами в конце бокового меню,
    // на телефоне в них ведут кнопки в профиле
    if (has_manager_rights()) $m[] = ['manage.php', 'shield', 'Управление', 'manage', '', true];
    if (is_sysadmin()) $m[] = ['admin.php', 'key', 'Администрирование', 'admin', '', true];
    return $m;
}

/** Вкладки раздела «Управление» */
function manage_tabs(string $on): void {
    $tabs = ['atts' => ['manage.php', 'Аттестации'], 'instructors' => ['instructors.php', 'Инструкторы']];
    echo '<section class="page-head manage-head"><div><h1>Управление</h1>'
       . '<div class="muted">Аттестации, которые вы ведёте, и инструкторы. Оценивать аттестуемых — в разделе «Аттестации».</div></div>'
       . '<a class="btn primary" href="attestation_wizard.php">' . icon('plus') . ' Создать аттестацию</a></section>';
    echo '<nav class="seg-tabs">';
    foreach ($tabs as $k => [$href, $label]) echo '<a class="' . ($k === $on ? 'is-on' : '') . '" href="' . $href . '">' . e($label) . '</a>';
    echo '</nav>';
}

/** Общая часть <head>: иконки, манифест приложения, стили */
function head_html(string $title): void {
    ?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title) ?> · СМСО «Эпсилон»</title>
    <meta name="theme-color" content="#004b88">
    <meta name="description" content="<?= e(site_description()) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="СМСО">
    <script>
    /* Манифест приложения создаётся прямо в браузере: файл .webmanifest на InfinityFree
       перехватывает защитная проверка хостинга, и телефон предлагает только «ярлык» вместо установки. */
    (function () {
      document.documentElement.classList.add('js');
      try {
        var b = location.href.replace(/[?#].*$/, '').replace(/[^\/]*$/, '');
        var m = {
          id: b, name: 'СМСО «Эпсилон» — аттестация', short_name: 'СМСО', description: 'Аттестация по первой помощи',
          lang: 'ru', start_url: b + 'index.php', scope: b, display: 'standalone',
          background_color: '#f2f5f9', theme_color: '#004b88',
          icons: [
            { src: b + 'icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
            { src: b + 'icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
            { src: b + 'icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' }
          ]
        };
        var l = document.createElement('link');
        l.rel = 'manifest';
        l.href = URL.createObjectURL(new Blob([JSON.stringify(m)], { type: 'application/manifest+json' }));
        document.head.appendChild(l);
      } catch (e) {}
    })();
    </script>
    <link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="64x64" href="icons/favicon-64.png">
    <link rel="icon" type="image/svg+xml" href="icons/logo.svg">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <link rel="stylesheet" href="styles.css?v=23">
</head>
<?php
}

function page_start(string $title, array $o = []): void {
    $back    = $o['back'] ?? null;
    $width   = $o['width'] ?? 'wide';     // wide | narrow
    $bare    = $o['bare'] ?? false;       // без меню (вход/регистрация)
    $section = $o['section'] ?? 'att';    // подсветка пункта меню
    // страницы, открытые из раздела «Управление», подсвечивают его в меню
    if (in_array($section, ['att', 'instructors'], true) && is_admin()) $section = 'manage';
    $f = flash();
    $logged = !empty($_SESSION['user_id']) && !$bare;
    global $pdo;
    $unread = $logged ? unread_notifications($pdo) : 0;
    $fio = (string)($_SESSION['full_name'] ?? '');
    head_html($title);
    ?>
<body class="<?= $logged ? 'app' : 'guest' ?>">
<?php if ($logged):
    $menu = nav_items();
    $secLabel = '';
    foreach ($menu as $it) if ($it[3] === $section) $secLabel = $it[2]; ?>
<aside class="sidebar">
    <a class="side-brand" href="index.php"><span class="brand-mark">✚</span><span>СМСО <b>«Эпсилон»</b></span></a>
    <nav class="side-nav">
        <?php foreach ($menu as [$href, $ico, $label, $key]): ?>
            <a class="nav-btn <?= $section === $key ? 'is-active' : '' ?>" href="<?= $href ?>"><?= icon($ico) ?><span><?= e($label) ?></span>
                <?php if ($key === 'notifications' && $unread): ?><em class="count warn"><?= $unread ?></em><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="side-foot">
        <a class="nav-btn" href="logout.php"><?= icon('logout') ?><span>Выйти</span></a>
    </div>
</aside>
<div class="main">
<header class="topbar">
    <div class="topbar-left">
        <?php if ($back): ?>
            <a class="icon-btn ghost back-btn" href="<?= e($back) ?>" aria-label="Назад" title="Назад"><?= icon('left') ?></a>
            <span class="topbar-title"><?= e($secLabel) ?></span>
        <?php else: ?>
            <a class="mob-brand" href="index.php"><span class="brand-mark">✚</span><span>СМСО «Эпсилон»</span></a>
            <span class="topbar-title desk-only"><?= e($secLabel) ?></span>
        <?php endif; ?>
    </div>
    <div class="topbar-actions">
        <?php if (!is_participant()): ?>
            <a class="icon-btn ghost bell-btn desk-only" href="notifications.php" aria-label="Уведомления" title="Уведомления"><?= icon('bell') ?><?php if ($unread): ?><em class="dot-count"><?= $unread > 9 ? '9+' : $unread ?></em><?php endif; ?></a>
        <?php endif; ?>
        <a class="user-btn <?= $section === 'profile' ? 'is-active' : '' ?>" href="<?= is_participant() ? 'me.php' : 'profile.php' ?>" title="Профиль">
            <span class="avatar"><?= e(initials($fio)) ?></span>
            <span class="user-meta"><b><?= e(short_name($fio)) ?></b><small><?= e(role_label()) ?></small></span>
        </a>
    </div>
</header>
<?php else: ?>
<div class="main">
<?php endif; ?>
<main class="container <?= $width === 'narrow' ? 'narrow' : '' ?>">
    <?php if ($f): ?>
        <div class="alert <?= e($f['t']) ?>" role="status"><?= e($f['m']) ?></div>
    <?php endif; ?>
    <?php $notes = $logged ? take_notifications($pdo) : []; ?>
    <?php if ($notes): ?>
        <div class="toasts" aria-live="polite">
            <?php foreach ($notes as $n): ?>
                <div class="toast" role="alert">
                    <span class="toast-ico"><?= icon('mail') ?></span>
                    <div class="toast-body">
                        <b><?= e($n['message']) ?></b>
                        <?php if (($n['kind'] ?? '') === 'invite' && ($btns = invite_buttons($pdo, (int)$n['ref_id'], $_SERVER['REQUEST_URI'] ?? ''))): ?>
                            <?= $btns ?>
                        <?php elseif ($n['link'] && ($n['kind'] ?? '') !== 'invite'): ?>
                            <a class="btn small primary" href="<?= e($n['link']) ?>">Открыть</a>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="icon-btn" data-toast-close aria-label="Закрыть"><?= icon('x') ?></button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php
    $GLOBALS['PAGE_SECTION'] = $section;
    $GLOBALS['PAGE_LOGGED'] = $logged;
    $GLOBALS['PAGE_UNREAD'] = $unread;
}

function page_end(): void {
    $logged = $GLOBALS['PAGE_LOGGED'] ?? false;
    $section = $GLOBALS['PAGE_SECTION'] ?? '';
    $unread = $GLOBALS['PAGE_UNREAD'] ?? 0;
    ?>
</main>
</div>
<?php if (!$logged) guest_footer(); ?>
<?php if ($logged && !is_participant()): ?>
<nav class="tabbar" aria-label="Разделы">
    <?php foreach (nav_items() as $it): [$href, $ico, $label, $key, $shortLabel] = $it; if (!empty($it[5])) continue;
        $on = $section === $key || ($key === 'profile' && in_array($section, ['manage', 'admin'], true)); ?>
        <a class="tab <?= $on ? 'is-active' : '' ?>" href="<?= $href ?>"><?= icon($ico) ?><span><?= e($shortLabel) ?></span>
            <?php if ($key === 'notifications' && $unread): ?><em class="dot-count"><?= $unread > 9 ? '9+' : $unread ?></em><?php endif; ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<script src="app.js?v=23"></script>
</body>
</html>
<?php
}

/* ================= Данные ================= */

function get_attestation(PDO $pdo, int $id): ?array {
    $q = $pdo->prepare("SELECT * FROM attestations WHERE id = ?");
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** Участвует ли инструктор в аттестации (принял приглашение или вошёл по коду) */
function is_member(PDO $pdo, int $attId, int $userId): bool {
    return member_status($pdo, $attId, $userId) === 'active';
}

/** null — не участвует, 'invited' — приглашён и ещё не ответил, 'active' — участвует */
function member_status(PDO $pdo, int $attId, int $userId): ?string {
    $q = $pdo->prepare("SELECT status FROM attestation_members WHERE attestation_id = ? AND user_id = ?");
    $q->execute([$attId, $userId]);
    $s = $q->fetchColumn();
    return $s === false ? null : ($s ?: 'active');
}

/** Пригласить инструктора. Уведомление уходит сразу, если аттестация опубликована (для черновика — при публикации). */
function invite_member(PDO $pdo, array $att, int $userId): bool {
    if (member_status($pdo, (int)$att['id'], $userId) !== null) return false;
    $ok = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role IN ('instructor', 'admin', 'sysadmin')");
    $ok->execute([$userId]);
    if (!$ok->fetchColumn()) return false;
    $pdo->prepare("INSERT INTO attestation_members (attestation_id, user_id, status) VALUES (?, ?, 'invited')")->execute([$att['id'], $userId]);
    if ($att['status'] !== 'prep') notify_invite($pdo, $userId, $att);
    return true;
}

/** Приглашения, на которые инструктор ещё не ответил */
function my_invitations(PDO $pdo, int $userId): array {
    $q = $pdo->prepare("SELECT a.* FROM attestations a JOIN attestation_members m ON m.attestation_id = a.id
                        WHERE m.user_id = ? AND m.status = 'invited' AND a.status <> 'prep' ORDER BY a.id DESC");
    $q->execute([$userId]);
    return $q->fetchAll();
}

/** Аттестации, к которым присоединён ПИ */
function my_attestations(PDO $pdo, int $userId): array {
    $q = $pdo->prepare("
        SELECT a.*,
               (SELECT COUNT(*) FROM station_judges sj JOIN stations s ON s.id = sj.station_id
                 WHERE sj.user_id = m.user_id AND s.attestation_id = a.id) AS my_stations_cnt
        FROM attestations a
        JOIN attestation_members m ON m.attestation_id = a.id
        WHERE m.user_id = ? AND a.status <> 'prep' AND m.status = 'active'
        ORDER BY a.id DESC
    ");
    $q->execute([$userId]);
    return $q->fetchAll();
}

/** Присоединиться к аттестации по коду. Повторное присоединение безопасно. */
function join_attestation(PDO $pdo, int $userId, string $code): ?array {
    $q = $pdo->prepare("SELECT * FROM attestations WHERE join_code = ? AND status <> 'prep'");
    $q->execute([strtoupper(trim($code))]);
    $att = $q->fetch();
    if (!$att) return null;
    $st = member_status($pdo, (int)$att['id'], $userId);
    if ($st === null) {
        $pdo->prepare("INSERT INTO attestation_members (attestation_id, user_id, status) VALUES (?, ?, 'active')")
            ->execute([$att['id'], $userId]);
    } elseif ($st === 'invited') {
        // вход по коду равносилен принятию приглашения
        $pdo->prepare("UPDATE attestation_members SET status = 'active' WHERE attestation_id = ? AND user_id = ?")
            ->execute([$att['id'], $userId]);
    }
    return $att;
}

function parse_scores(string $s): array {
    $out = array_values(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== ''));
    usort($out, fn($a, $b) => (float)$a <=> (float)$b);
    return $out;
}

/** Нормализация ФИ: лишние пробелы убираются, регистр — «Иванов» */
function normalize_name(string $s): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (function_exists('mb_convert_case')) {
        $s = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
    return $s;
}

/** Найти участника по ФИ без учёта регистра или создать нового. */
function find_or_create_participant(PDO $pdo, string $last, string $first): int {
    $last  = normalize_name($last);
    $first = normalize_name($first);
    if ($last === '' || $first === '') {
        throw new InvalidArgumentException('Фамилия и имя обязательны');
    }
    $find = $pdo->prepare("SELECT id FROM participants WHERE LOWER(last_name) = LOWER(?) AND LOWER(first_name) = LOWER(?) ORDER BY id LIMIT 1");
    $find->execute([$last, $first]);
    if ($id = $find->fetchColumn()) {
        return (int)$id;
    }
    try {
        $pdo->prepare("INSERT INTO participants (last_name, first_name) VALUES (?, ?)")->execute([$last, $first]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // параллельно создали такого же — читаем существующего
        $find->execute([$last, $first]);
        if ($id = $find->fetchColumn()) return (int)$id;
        throw $e;
    }
}

/**
 * Станция + проверка доступа текущего пользователя.
 * ГИ видит любую станцию, ПИ — только те, где он судья.
 * Добавляет поля: att_title, att_status, is_judge, judge_approved, can_rename.
 */
function station_access(PDO $pdo, int $sid): ?array {
    $q = $pdo->prepare("
        SELECT s.*, a.title AS att_title, a.status AS att_status,
               a.allow_edit_admin_stations, a.allow_own_stations, a.one_judge_per_station
        FROM stations s JOIN attestations a ON a.id = s.attestation_id
        WHERE s.id = ?
    ");
    $q->execute([$sid]);
    $st = $q->fetch();
    if (!$st) return null;

    if (is_admin()) {
        $st['is_judge'] = false;
        $st['judge_approved'] = 1;
    } else {
        $j = $pdo->prepare("SELECT approved FROM station_judges WHERE station_id = ? AND user_id = ?");
        $j->execute([$sid, uid()]);
        $appr = $j->fetchColumn();
        if ($appr === false) return null;
        $st['is_judge'] = true;
        $st['judge_approved'] = (int)$appr;
    }
    $st['can_rename'] = is_admin() || !$st['created_by_admin'];
    // Критерии и порог станции ГИ инструктор меняет, только если это разрешено в настройках
    $st['can_edit'] = is_admin() || !$st['created_by_admin'] || (int)$st['allow_edit_admin_stations'] === 1;
    return $st;
}

function station_criteria(PDO $pdo, int $sid): array {
    $q = $pdo->prepare("SELECT * FROM criteria WHERE station_id = ? ORDER BY id");
    $q->execute([$sid]);
    return $q->fetchAll();
}

/** Станции ПИ в аттестации (со статусом допуска и счётчиками) */
function my_stations(PDO $pdo, int $userId, int $attId): array {
    $q = $pdo->prepare("
        SELECT s.*, sj.approved AS judge_approved,
               (SELECT COUNT(*) FROM criteria c WHERE c.station_id = s.id) AS crit_cnt,
               (SELECT COUNT(DISTINCT r.participant_id) FROM results r WHERE r.station_id = s.id) AS part_cnt
        FROM stations s
        JOIN station_judges sj ON sj.station_id = s.id
        WHERE sj.user_id = ? AND s.attestation_id = ?
        ORDER BY s.id
    ");
    $q->execute([$userId, $attId]);
    return $q->fetchAll();
}

/** Результаты станции: участники × критерии */
function station_results(PDO $pdo, int $stationId): array {
    $p = $pdo->prepare("
        SELECT DISTINCT p.id AS pid, p.last_name, p.first_name
        FROM results r JOIN participants p ON p.id = r.participant_id
        WHERE r.station_id = ?
        ORDER BY p.last_name, p.first_name
    ");
    $p->execute([$stationId]);
    $participants = $p->fetchAll();

    $scores = [];
    if ($participants) {
        $r = $pdo->prepare("SELECT participant_id, criteria_id, score FROM results WHERE station_id = ?");
        $r->execute([$stationId]);
        foreach ($r->fetchAll() as $row) {
            $scores[(int)$row['participant_id']][(int)$row['criteria_id']] = $row['score'];
        }
    }
    return [
        'participants' => $participants,
        'criteria'     => station_criteria($pdo, $stationId),
        'scores'       => $scores,
    ];
}

/* ================= Настройки аттестации ================= */

const ATT_SETTINGS = [
    'one_judge_per_station' => ['Один инструктор — одна станция',
        'На станции может работать только один инструктор, и у инструктора только одна станция.'],
    'allow_own_stations' => ['Инструкторы могут создавать свои станции',
        'Если выключено, инструкторы выбирают только из станций сотрудника управления.'],
    'allow_edit_admin_stations' => ['Инструкторы могут редактировать станции управления',
        'Менять критерии и порог сдачи у станций, созданных сотрудником управления.'],
    'participants_by_admin' => ['Список аттестуемых заполняет сотрудник управления',
        'Аттестуемых заранее вносят на вкладке «Участники», инструктор выбирает человека из списка. Если выключено — инструктор вводит фамилию и имя на месте.'],
    'self_register' => ['Самостоятельная регистрация аттестуемых',
        'Аттестуемые регистрируются сами по QR-коду или ссылке: фамилия, имя, отчество, пол и дата рождения. Учётная запись действует, пока существует аттестация, и удаляется вместе с ней. Инструктор выбирает человека из списка.'],
    'show_scores' => ['Аттестуемый видит свои баллы сразу',
        'Если выключено — до подтверждения результатов аттестуемый видит только, какие станции он прошёл, без баллов. Баллы и итог появляются после подтверждения.'],
    'allow_retake' => ['Разрешить повторное прохождение станции',
        'Инструктор может ещё раз оценить того же человека на своей станции — засчитывается последняя попытка. Если выключено — результат станции заполняется один раз.'],
];

/** Переключатели настроек (для формы создания и вкладки «Настройки») */
function settings_switches(array $values): void {
    echo '<div class="switches">';
    foreach (ATT_SETTINGS as $key => [$label, $hint]) {
        $on = !empty($values[$key]);
        echo '<label class="switch"><input type="hidden" name="' . $key . '" value="0">'
           . '<input type="checkbox" name="' . $key . '" value="1"' . ($on ? ' checked' : '') . '>'
           . '<span class="switch-track"></span>'
           . '<span class="switch-text"><b>' . e($label) . '</b><span class="muted small">' . e($hint) . '</span></span></label>';
    }
    echo '</div>';
}

/** Поле порога сдачи аттестации */
function att_threshold_field(int $value, bool $locked = false, ?int $stCount = null, string $mode = 'avg'): void {
    echo '<fieldset class="verdict-mode" data-verdict-mode><legend>Как считать итог «сдал / не сдал»</legend>';
    foreach (VERDICT_MODES as $k => [$label, $desc]) {
        echo '<label class="choice-item"><input type="radio" name="verdict_mode" value="' . $k . '"' . ($mode === $k ? ' checked' : '')
           . ($locked ? ' disabled' : '') . '><span><b>' . e($label) . '</b><small>' . e($desc) . '</small></span></label>';
    }
    if ($locked) echo '<input type="hidden" name="verdict_mode" value="' . e($mode) . '">';
    echo '</fieldset>';
    echo '<label class="att-threshold">Порог сдачи аттестации'
       . '<span class="muted small" data-mode-hint="avg"' . ($mode === 'avg' ? '' : ' hidden') . '>Средний процент по станциям должен быть не ниже порога. Минимум ' . PASS_MIN . '%.</span>'
       . '<span class="muted small" data-mode-hint="stations"' . ($mode === 'stations' ? '' : ' hidden') . '>Доля станций, которые нужно сдать. Минимум ' . PASS_MIN . '%.</span>'
       . '<span class="threshold-input small-input"><input type="number" name="pass_threshold" min="' . PASS_MIN . '" max="100" step="1" value="' . $value . '" required' . ($locked ? ' readonly' : '') . '><span>%</span></span>'
       . '</label>';
    if ($stCount !== null) {
        echo '<p class="muted small need-hint" data-need-hint data-st="' . $stCount . '"' . ($mode === 'stations' ? '' : ' hidden') . '>' . ($stCount
            ? 'Сейчас станций: ' . $stCount . ' — <b data-need-text>' . e(needed_text(stations_needed($stCount, $value), $stCount)) . '</b>.'
            : 'Станций с критериями пока нет — число нужных станций посчитается от порога, когда они появятся.') . '</p>';
    }
    if ($locked) {
        echo '<p class="muted small">Порог и способ подсчёта итога нельзя изменить: приём аттестуемых уже начался.</p>';
        return;
    }
    echo '<div class="chips preset-chips">';
    foreach ([50, 60, 70, 80, 90, 100] as $p) {
        echo '<button type="button" class="chip-btn" data-preset="' . $p . '">' . $p . '%</button>';
    }
    echo '</div>';
}

function read_att_threshold(array $src): int {
    return max(PASS_MIN, min(100, (int)($src['pass_threshold'] ?? PASS_DEFAULT)));
}

/** Сохранить настройки аттестации (все переключатели + порог) */
function save_att_settings(PDO $pdo, int $attId, array $set, int $threshold, ?string $mode = null): void {
    $cols = []; $vals = [];
    foreach ($set as $k => $v) { $cols[] = "`$k` = ?"; $vals[] = $v; }
    $cols[] = 'pass_threshold = ?'; $vals[] = $threshold;
    if ($mode !== null) { $cols[] = 'verdict_mode = ?'; $vals[] = $mode; }
    $vals[] = $attId;
    $pdo->prepare("UPDATE attestations SET " . implode(', ', $cols) . " WHERE id = ?")->execute($vals);
}

function read_settings(array $src): array {
    $out = [];
    foreach (array_keys(ATT_SETTINGS) as $k) $out[$k] = !empty($src[$k]) ? 1 : 0;
    return $out;
}

/* ================= Приглашения ================= */

/** Все, кого можно пригласить (инструкторы и сотрудники управления, кроме себя) */
function invitable_people(PDO $pdo): array {
    $q = $pdo->prepare("SELECT id, full_name, username, role FROM users WHERE role IN ('instructor', 'admin', 'sysadmin') AND id <> ? ORDER BY full_name");
    $q->execute([uid()]);
    return $q->fetchAll();
}

/** Список с галочками и поиском */
function invite_checklist(array $people, array $checked = []): void {
    if (!$people) {
        echo '<p class="muted small">Зарегистрированных инструкторов пока нет — пригласить можно будет позже по коду или QR.</p>';
        return;
    }
    echo '<div class="invite-box" data-select-all-group>';
    echo '<div class="invite-head"><input type="search" class="search" placeholder="Поиск" data-filter-local=".invite-item">'
       . '<label class="chk-all"><input type="checkbox" data-select-all> выбрать всех</label></div>';
    echo '<div class="invite-list">';
    foreach ($people as $p) {
        echo '<label class="invite-item"><input type="checkbox" name="users[]" value="' . (int)$p['id'] . '"'
           . (in_array((int)$p['id'], $checked, true) ? ' checked' : '') . '>'
           . '<span><b>' . e($p['full_name']) . '</b> <span class="muted small">@' . e($p['username'])
           . ($p['role'] === 'admin' ? ' · сотрудник управления' : ($p['role'] === 'sysadmin' ? ' · администратор' : '')) . '</span></span></label>';
    }
    echo '</div></div>';
}

/* ================= Курсы и уроки ================= */
/*
 * Курс-черновик (status = 'draft') виден только автору — блок «Мои курсы».
 * Опубликованный курс (status = 'published') виден всем. Менять курс может автор,
 * а опубликованный — ещё и сотрудник управления.
 */
function get_course(PDO $pdo, int $id): ?array {
    $q = $pdo->prepare("SELECT c.*, u.full_name AS author FROM courses c LEFT JOIN users u ON u.id = c.created_by WHERE c.id = ?");
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function course_can_view(array $c): bool {
    return ($c['status'] ?? 'published') === 'published' || (int)$c['created_by'] === uid();
}

function course_can_edit(array $c): bool {
    return (int)$c['created_by'] === uid() || (($c['status'] ?? 'published') === 'published' && has_manager_rights());
}

function course_lessons(PDO $pdo, int $courseId): array {
    $q = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order, id");
    $q->execute([$courseId]);
    return $q->fetchAll();
}

/** Разделы урока с пунктами: [ [id, title, items => [...]], ... ] */
function lesson_structure(PDO $pdo, int $lessonId): array {
    $sq = $pdo->prepare("SELECT * FROM lesson_sections WHERE lesson_id = ? ORDER BY sort_order, id");
    $sq->execute([$lessonId]);
    $sections = $sq->fetchAll();
    if (!$sections) return [];
    $iq = $pdo->prepare("SELECT i.* FROM lesson_items i JOIN lesson_sections s ON s.id = i.section_id WHERE s.lesson_id = ? ORDER BY i.sort_order, i.id");
    $iq->execute([$lessonId]);
    $by = [];
    foreach ($iq->fetchAll() as $it) $by[(int)$it['section_id']][] = $it;
    foreach ($sections as &$sec) $sec['items'] = $by[(int)$sec['id']] ?? [];
    unset($sec);
    return $sections;
}

/** Прогресс пользователя по урокам курса: [lesson_id => [done, total, completed]] */
function course_progress(PDO $pdo, int $courseId, int $userId): array {
    $out = [];
    $t = $pdo->prepare("SELECT l.id, COUNT(i.id) AS total FROM lessons l
                        LEFT JOIN lesson_sections s ON s.lesson_id = l.id LEFT JOIN lesson_items i ON i.section_id = s.id
                        WHERE l.course_id = ? GROUP BY l.id");
    $t->execute([$courseId]);
    foreach ($t->fetchAll() as $r) $out[(int)$r['id']] = ['done' => 0, 'total' => (int)$r['total'], 'completed' => false];
    $d = $pdo->prepare("SELECT p.lesson_id, COUNT(*) AS n FROM lesson_progress p JOIN lessons l ON l.id = p.lesson_id
                        WHERE l.course_id = ? AND p.user_id = ? GROUP BY p.lesson_id");
    $d->execute([$courseId, $userId]);
    foreach ($d->fetchAll() as $r) if (isset($out[(int)$r['lesson_id']])) $out[(int)$r['lesson_id']]['done'] = (int)$r['n'];
    $c = $pdo->prepare("SELECT d.lesson_id FROM lesson_done d JOIN lessons l ON l.id = d.lesson_id
                        WHERE l.course_id = ? AND d.user_id = ? AND d.completed_at IS NOT NULL");
    $c->execute([$courseId, $userId]);
    foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $lid) if (isset($out[(int)$lid])) $out[(int)$lid]['completed'] = true;
    return $out;
}

/** Личная копия курса: курс, уроки, подразделы и пункты. Копия — черновик нового автора. */
function copy_course(PDO $pdo, int $courseId, int $userId): int {
    $c = get_course($pdo, $courseId);
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO courses (title, description, created_by, updated_by, updated_at, status) VALUES (?, ?, ?, ?, ?, 'draft')")
            ->execute([$c['title'] . ' (моя копия)', $c['description'], $userId, $userId, $now]);
        $newId = (int)$pdo->lastInsertId();
        foreach (course_lessons($pdo, $courseId) as $l) {
            $pdo->prepare("INSERT INTO lessons (course_id, title, description, content, sort_order, created_by, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$newId, $l['title'], $l['description'], $l['content'], $l['sort_order'], $userId, $userId, $now]);
            $newL = (int)$pdo->lastInsertId();
            foreach (lesson_structure($pdo, (int)$l['id']) as $sec) {
                $pdo->prepare("INSERT INTO lesson_sections (lesson_id, title, sort_order) VALUES (?, ?, ?)")->execute([$newL, $sec['title'], $sec['sort_order']]);
                $newS = (int)$pdo->lastInsertId();
                foreach ($sec['items'] as $it) {
                    $pdo->prepare("INSERT INTO lesson_items (section_id, title, content, sort_order) VALUES (?, ?, ?, ?)")
                        ->execute([$newS, $it['title'], $it['content'], $it['sort_order']]);
                }
            }
        }
        $pdo->commit();
        return $newId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Удалить урок со всей структурой и прогрессом */
function delete_lesson(PDO $pdo, int $lessonId): void {
    $pdo->prepare("DELETE FROM lesson_items WHERE section_id IN (SELECT id FROM lesson_sections WHERE lesson_id = ?)")->execute([$lessonId]);
    $pdo->prepare("DELETE FROM lesson_sections WHERE lesson_id = ?")->execute([$lessonId]);
    $pdo->prepare("DELETE FROM lesson_progress WHERE lesson_id = ?")->execute([$lessonId]);
    $pdo->prepare("DELETE FROM lesson_done WHERE lesson_id = ?")->execute([$lessonId]);
    $pdo->prepare("DELETE FROM lessons WHERE id = ?")->execute([$lessonId]);
}

/** Переставить запись вверх/вниз внутри группы: $table, $groupCol = значение группы */
function move_sorted(PDO $pdo, string $table, string $groupCol, int $groupId, int $id, int $dir): void {
    $q = $pdo->prepare("SELECT id FROM `$table` WHERE `$groupCol` = ? ORDER BY sort_order, id");
    $q->execute([$groupId]);
    $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $pos = array_search($id, $ids, true);
    if ($pos === false || !isset($ids[$pos + $dir])) return;
    [$ids[$pos], $ids[$pos + $dir]] = [$ids[$pos + $dir], $ids[$pos]];
    $u = $pdo->prepare("UPDATE `$table` SET sort_order = ? WHERE id = ?");
    foreach ($ids as $i => $x) $u->execute([$i + 1, $x]);
}

function next_sort(PDO $pdo, string $table, string $groupCol, int $groupId): int {
    $q = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `$table` WHERE `$groupCol` = ?");
    $q->execute([$groupId]);
    return (int)$q->fetchColumn();
}

/** Шаги мастеров (общий вид): $steps = [n => [label, url|null|'submit:formId']] */
function steps_nav(array $steps, int $current): void {
    echo '<ol class="steps steps-wide" style="grid-template-columns: repeat(' . count($steps) . ', 1fr)">';
    foreach ($steps as $n => [$label, $target]) {
        $cls = $n < $current ? 'done' : ($n === $current ? 'active' : '');
        $inner = '<span>' . ($n < $current ? '✓' : $n) . '</span>' . e($label);
        if ($n === $current) {
            $el = '<span class="step-in">' . $inner . '</span>';
        } elseif ($target && str_starts_with($target, 'submit:')) {
            $el = '<button class="step-in" type="submit" form="' . e(substr($target, 7)) . '" name="goto" value="' . $n . '">' . $inner . '</button>';
        } elseif ($target) {
            $el = '<a class="step-in" href="' . e($target) . '">' . $inner . '</a>';
        } else {
            $el = '<span class="step-in disabled">' . $inner . '</span>';
        }
        echo '<li class="' . $cls . '">' . $el . '</li>';
    }
    echo '</ol>';
}

/* ================= Мастер станции ================= */

/**
 * Параметры мастера станции, которые передаются со страницы на страницу:
 * for — для какого инструктора создаётся станция, ret=wiz — вернуться в мастер аттестации.
 */
function station_wiz_qs(): string {
    if (empty($_GET['wizard']) && empty($_POST['wizard'])) return '';
    $for = (int)($_GET['for'] ?? $_POST['for'] ?? 0);
    $ret = preg_replace('/[^a-z]/', '', (string)($_GET['ret'] ?? $_POST['ret'] ?? ''));
    return "&wizard=1&for=$for" . ($ret ? "&ret=$ret" : '');
}

/** Шаги мастера станции — кликабельные, если станция уже создана */
function wizard_steps(int $current, int $sid = 0): void {
    $qs = station_wiz_qs() ?: '&wizard=1';
    $steps = [
        1 => ['Название', $sid ? "station_new.php?station=$sid$qs" : null],
        2 => ['Критерии', $sid ? "criteria_edit.php?station=$sid$qs" : null],
        3 => ['Порог сдачи', $sid ? "threshold.php?station=$sid$qs" : null],
    ];
    echo '<ol class="steps">';
    foreach ($steps as $n => [$label, $url]) {
        $cls = $n < $current ? 'done' : ($n === $current ? 'active' : '');
        $inner = '<span>' . ($n < $current ? '✓' : $n) . '</span>' . e($label);
        if ($n === $current) {
            $el = '<span class="step-in">' . $inner . '</span>';
        } elseif ($url) {
            $el = '<a class="step-in" href="' . e($url) . '">' . $inner . '</a>';
        } else {
            // станция ещё не создана — шаг сохраняет название и переходит дальше
            $el = '<button class="step-in" type="submit" form="station-name-form" name="goto" value="' . $n . '">' . $inner . '</button>';
        }
        echo '<li class="' . $cls . '">' . $el . '</li>';
    }
    echo '</ol>';
}

/** Куда вести после завершения мастера станции */
function station_finish_url(array $st, int $for = 0): string {
    $att = (int)$st['attestation_id'];
    $ret = (string)($_GET['ret'] ?? $_POST['ret'] ?? '');
    if (is_admin() && $ret === 'wiz') return "attestation_wizard.php?id=$att&step=2";
    if (is_admin()) {
        return $for ? "attestation.php?id=$att&assign=$for#instructors" : "attestation.php?id=$att#stations";
    }
    return 'station_instructor.php?id=' . (int)$st['id'];
}

/* ================= Станции: блокировка и окно с критериями ================= */

/** Можно ли менять станции аттестации (черновик или идёт) */
function att_editable(string $status): bool {
    return in_array($status, ['prep', 'draft'], true);
}

/** Начат ли приём аттестуемых: есть хотя бы один результат в аттестации.
 *  После этого состав станций, критерии и пороги не меняются — даже сотрудником управления. */
function att_started(PDO $pdo, int $attId): bool {
    static $cache = [];
    if (!isset($cache[$attId])) {
        $q = $pdo->prepare("SELECT 1 FROM results r JOIN stations s ON s.id = r.station_id WHERE s.attestation_id = ? LIMIT 1");
        $q->execute([$attId]);
        $cache[$attId] = (bool)$q->fetchColumn();
    }
    return $cache[$attId];
}

const STARTED_MSG = 'Приём аттестуемых уже начался — станции, критерии и пороги сдачи изменить нельзя';

/* ================= Уведомления ================= */

function notify(PDO $pdo, int $userId, string $message, ?string $link = null, ?string $kind = null, ?int $ref = null): void {
    $pdo->prepare("INSERT INTO notifications (user_id, message, link, kind, ref_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$userId, $message, $link, $kind, $ref]);
    // копия на телефон (если человек включил push-уведомления)
    try { push_queue($userId, $message, $link, $kind); } catch (Throwable $e) { error_log('push_queue: ' . $e->getMessage()); }
}

/** Уведомить о приглашении в аттестацию (с кнопками «Принять» / «Отклонить») */
function notify_invite(PDO $pdo, int $userId, array $att): void {
    notify($pdo, $userId, 'Вас пригласили инструктором в аттестацию «' . $att['title'] . '»', 'notifications.php', 'invite', (int)$att['id']);
}

/** Уведомить о новых полномочиях */
function notify_role(PDO $pdo, int $userId, string $role): void {
    $text = [
        'admin'      => 'Вам присвоены полномочия сотрудника управления',
        'sysadmin'   => 'Вам присвоены права администратора',
        'instructor' => 'Ваша роль изменена: теперь вы инструктор',
    ][$role] ?? 'Ваша роль изменена';
    notify($pdo, $userId, $text, 'index.php', 'role');
}

/** Кнопки ответа на приглашение (если оно ещё в силе) */
function invite_buttons(PDO $pdo, int $attId, string $back = ''): string {
    if (member_status($pdo, $attId, uid()) !== 'invited') return '';
    return '<form method="post" action="invite_respond.php" class="btn-row invite-actions">'
         . '<input type="hidden" name="att" value="' . $attId . '"><input type="hidden" name="back" value="' . e($back) . '">'
         . '<button name="answer" value="accept" class="btn small primary">' . icon('check') . ' Принять</button>'
         . '<button name="answer" value="decline" class="btn small" data-confirm="Отклонить приглашение?">Отклонить</button></form>';
}

function unread_notifications(PDO $pdo): int {
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
        $q->execute([uid()]);
        return (int)$q->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** Непоказанные уведомления — показываем всплывающим окном и отмечаем прочитанными */
function take_notifications(PDO $pdo): array {
    if (empty($_SESSION['user_id'])) return [];
    try {
        $q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND seen_at IS NULL ORDER BY id LIMIT 5");
        $q->execute([uid()]);
        $list = $q->fetchAll();
        if ($list) {
            $pdo->prepare("UPDATE notifications SET seen_at = ? WHERE user_id = ? AND seen_at IS NULL")->execute([date('Y-m-d H:i:s'), uid()]);
        }
        return $list;
    } catch (PDOException $e) {
        return [];   // таблица ещё не создана
    }
}

/** Сколько человек уже оценено на станции. Если > 0 — критерии менять нельзя. */
function station_scored_count(PDO $pdo, int $sid): int {
    $q = $pdo->prepare("SELECT COUNT(DISTINCT participant_id) FROM results WHERE station_id = ?");
    $q->execute([$sid]);
    return (int)$q->fetchColumn();
}

/**
 * Окно с критериями станции.
 * $actions — HTML кнопок, которые встанут внизу рядом с «Назад».
 */
function station_dialog(string $dlgId, array $st, array $criteria, string $actions = ''): void {
    ?>
    <dialog class="modal station-modal" id="<?= e($dlgId) ?>">
        <div class="modal-head">
            <h2 class="mt0"><?= e($st['name']) ?></h2>
            <button type="button" class="icon-btn" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button>
        </div>
        <div class="muted small">
            <?= plural(count($criteria), 'критерий', 'критерия', 'критериев') ?> · порог сдачи <?= (int)$st['pass_threshold'] ?>%
            <?= !empty($st['created_by_admin']) ? ' · станция управления' : '' ?>
        </div>
        <?php if (!$criteria): ?>
            <p class="muted">Критерии ещё не заданы.</p>
        <?php else: ?>
            <ol class="crit-list modal-crit">
                <?php foreach ($criteria as $c): ?>
                    <li><span class="crit-name"><?= e($c['name']) ?></span>
                        <span class="score-set"><?php foreach (parse_scores($c['allowed_scores']) as $sc): ?><i><?= e($sc) ?></i><?php endforeach; ?></span></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <div class="modal-actions">
            <button type="button" class="btn" data-dialog-close>← Назад</button>
            <?= $actions ?>
        </div>
    </dialog>
    <?php
}

/* ================= Аттестуемые аттестации ================= */

/** Список аттестуемых, внесённых сотрудником управления */
/** Добавить аттестуемого в список аттестации со следующим номером (нумерация в каждой аттестации с 1) */
function roster_add(PDO $pdo, int $attId, int $pid, ?int $by = null): int {
    $m = $pdo->prepare("SELECT COALESCE(MAX(num), 0) + 1 FROM attestation_participants WHERE attestation_id = ?");
    $m->execute([$attId]);
    $num = (int)$m->fetchColumn();
    $pdo->prepare("INSERT INTO attestation_participants (attestation_id, participant_id, added_by, num) VALUES (?, ?, ?, ?)")
        ->execute([$attId, $pid, $by, $num]);
    return $num;
}

/** Номер аттестуемого в аттестации (null — не в списке) */
function participant_num(PDO $pdo, int $attId, int $pid): ?int {
    $q = $pdo->prepare("SELECT num FROM attestation_participants WHERE attestation_id = ? AND participant_id = ?");
    $q->execute([$attId, $pid]);
    $n = $q->fetchColumn();
    return $n === false || $n === null ? null : (int)$n;
}

function att_roster(PDO $pdo, int $attId): array {
    $q = $pdo->prepare("SELECT p.*, ap.num FROM participants p JOIN attestation_participants ap ON ap.participant_id = p.id
                        WHERE ap.attestation_id = ? ORDER BY p.last_name, p.first_name");
    $q->execute([$attId]);
    return $q->fetchAll();
}

/** Инструктор выбирает аттестуемого из списка (список ведёт управление или люди регистрируются сами) */
function roster_mode(array $att): bool {
    return (int)($att['participants_by_admin'] ?? 0) === 1 || (int)($att['self_register'] ?? 0) === 1;
}

/**
 * Код регистрации аттестуемых — отдельный от кода инструкторов,
 * чтобы аттестуемый не мог по нему присоединиться к аттестации судьёй.
 */
function att_reg_code(PDO $pdo, array $att): string {
    if (!empty($att['reg_code'])) return $att['reg_code'];
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $a[random_int(0, strlen($a) - 1)];
        $q = $pdo->prepare("SELECT 1 FROM attestations WHERE reg_code = ? OR join_code = ?");
        $q->execute([$code, $code]);
    } while ($q->fetchColumn());
    $pdo->prepare("UPDATE attestations SET reg_code = ? WHERE id = ?")->execute([$code, $att['id']]);
    return $code;
}

/** ФИО аттестуемого для списков */
function participant_name(array $p): string {
    return trim($p['last_name'] . ' ' . $p['first_name'] . ' ' . ($p['middle_name'] ?? ''));
}

/** Учётные записи аттестуемых этой аттестации — удаляются вместе с ней */
function delete_participant_accounts(PDO $pdo, int $attId): int {
    $q = $pdo->prepare("SELECT id, participant_id FROM users WHERE role = 'participant' AND attestation_id = ?");
    $q->execute([$attId]);
    $n = 0;
    foreach ($q->fetchAll() as $u) {
        $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$u['id']]);
        $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?")->execute([$u['id']]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$u['id']]);
        if ($u['participant_id']) {
            $pdo->prepare("DELETE FROM results WHERE participant_id = ?")->execute([$u['participant_id']]);
            $pdo->prepare("DELETE FROM attestation_participants WHERE participant_id = ?")->execute([$u['participant_id']]);
            $pdo->prepare("DELETE FROM participants WHERE id = ?")->execute([$u['participant_id']]);
        }
        $n++;
    }
    $pdo->prepare("DELETE FROM attestation_participants WHERE attestation_id = ?")->execute([$attId]);
    return $n;
}

function in_roster(PDO $pdo, int $attId, int $pid): bool {
    $q = $pdo->prepare("SELECT 1 FROM attestation_participants WHERE attestation_id = ? AND participant_id = ?");
    $q->execute([$attId, $pid]);
    return (bool)$q->fetchColumn();
}

/** Есть ли у человека сохранённый результат на станции */
function has_station_result(PDO $pdo, int $sid, int $pid): bool {
    $q = $pdo->prepare("SELECT 1 FROM results WHERE station_id = ? AND participant_id = ? LIMIT 1");
    $q->execute([$sid, $pid]);
    return (bool)$q->fetchColumn();
}

/* ================= Сдал / не сдал ================= */

const PASS_MIN = 50;
const PASS_DEFAULT = 60;

function pct(float $got, float $max): float {
    return $max > 0 ? round($got / $max * 100, 1) : 0.0;
}

/** Сдана ли станция: все критерии оценены и процент не ниже порога. null — оценка не завершена. */
function station_passed(float $sum, int $cnt, int $max, int $threshold): ?bool {
    if ($max <= 0 || $cnt < $max) return null;
    return pct($sum, $max) >= $threshold;
}

function fmt_pct(float $p): string {
    return rtrim(rtrim(number_format($p, 1, ',', ''), '0'), ',') . '%';
}

/**
 * Сводная таблица аттестации.
 * stations: [sid => [name, max, threshold]]
 * rows: [pid => [name, cells[sid] => [sum, cnt, passed], total, pct, verdict]]
 * Итог аттестации: сумма баллов по всем станциям / максимум по всем станциям ≥ порога аттестации.
 * verdict: pass — порог набран; fail — порог уже не набрать; pending — ещё не всё оценено и итог не ясен.
 */
function attestation_matrix(PDO $pdo, int $attId): array {
    $tq = $pdo->prepare("SELECT pass_threshold, verdict_mode FROM attestations WHERE id = ?");
    $tq->execute([$attId]);
    $ta = $tq->fetch() ?: [];
    $attThreshold = (int)($ta['pass_threshold'] ?? 0) ?: PASS_DEFAULT;
    $mode = ($ta['verdict_mode'] ?? 'avg') === 'stations' ? 'stations' : 'avg';
    $st = $pdo->prepare("
        SELECT s.id, s.name, s.pass_threshold,
               (SELECT COUNT(*) FROM criteria c WHERE c.station_id = s.id) AS max_score,
               (SELECT COUNT(*) FROM results r WHERE r.station_id = s.id) AS res_cnt,
               (SELECT COUNT(*) FROM station_judges sj WHERE sj.station_id = s.id AND sj.approved = 1) AS judges_ok
        FROM stations s WHERE s.attestation_id = ? ORDER BY s.id
    ");
    $st->execute([$attId]);
    $stations = [];
    foreach ($st->fetchAll() as $s) {
        if (((int)$s['res_cnt'] > 0 || (int)$s['judges_ok'] > 0) && (int)$s['max_score'] > 0) {
            $stations[(int)$s['id']] = [
                'name' => $s['name'],
                'max' => (int)$s['max_score'],
                'threshold' => (int)$s['pass_threshold'],
            ];
        }
    }

    $q = $pdo->prepare("
        SELECT r.participant_id AS pid, r.station_id AS sid, SUM(r.score) AS total, COUNT(*) AS cnt
        FROM results r JOIN stations s ON s.id = r.station_id
        WHERE s.attestation_id = ?
        GROUP BY r.participant_id, r.station_id
    ");
    $q->execute([$attId]);
    $cells = $q->fetchAll();

    $rows = [];
    if ($cells) {
        $p = $pdo->prepare("
            SELECT DISTINCT p.id, p.last_name, p.first_name
            FROM participants p
            JOIN results r ON r.participant_id = p.id
            JOIN stations s ON s.id = r.station_id
            WHERE s.attestation_id = ?
            ORDER BY p.last_name, p.first_name
        ");
        $p->execute([$attId]);
        foreach ($p->fetchAll() as $row) {
            $rows[(int)$row['id']] = [
                'name'    => $row['last_name'] . ' ' . $row['first_name'],
                'cells'   => [],
                'total'   => 0.0,
                'verdict' => 'pending',
            ];
        }
        foreach ($cells as $c) {
            $pid = (int)$c['pid']; $sid = (int)$c['sid'];
            if (!isset($rows[$pid], $stations[$sid])) continue;
            $s = $stations[$sid];
            $rows[$pid]['cells'][$sid] = [
                'sum'    => (float)$c['total'],
                'cnt'    => (int)$c['cnt'],
                'passed' => station_passed((float)$c['total'], (int)$c['cnt'], $s['max'], $s['threshold']),
            ];
            $rows[$pid]['total'] += (float)$c['total'];
        }
    }
    /*
     * Итог аттестации — в одном из двух режимов (attestations.verdict_mode):
     *   avg      — среднее процентов по станциям (каждая станция в 100%) не ниже порога аттестации;
     *              например (90 + 55,5 + 60 + 71,3) / 4 = 69,2%;
     *   stations — число сданных станций не меньше порога (доля станций в %).
     * Неоценённая станция в среднем пока считается как 0%.
     */
    $maxTotal = array_sum(array_column($stations, 'max'));
    $stCount = count($stations);
    $needed = stations_needed($stCount, $attThreshold);
    foreach ($rows as &$r) {
        $passedCnt = 0; $decided = 0; $pctSum = 0.0; $bestSum = 0.0;
        foreach ($stations as $sid => $s) {
            $c = $r['cells'][$sid] ?? null;
            if (!$c) { $bestSum += 100; continue; }                 // станция не начата: в лучшем случае 100%
            $pctSum  += pct($c['sum'], $s['max']);
            $bestSum += pct($c['sum'] + max(0, $s['max'] - $c['cnt']), $s['max']);
            if ($c['passed'] === null) continue;                    // оценена не полностью
            $decided++;
            if ($c['passed']) $passedCnt++;
        }
        $r['st_passed'] = $passedCnt;
        $r['pct'] = $stCount ? round($pctSum / $stCount, 1) : 0.0;
        $allDone = $stCount > 0 && $decided >= $stCount;
        if ($mode === 'avg') {
            $best = $stCount ? $bestSum / $stCount : 0;
            if ($stCount > 0 && $r['pct'] >= $attThreshold) {
                $r['verdict'] = 'pass';
            } elseif ($stCount > 0 && $best < $attThreshold) {
                $r['verdict'] = 'fail';
            } else {
                $r['verdict'] = $allDone ? 'fail' : 'pending';
            }
        } else {
            $possible = $passedCnt + ($stCount - $decided);   // если все незавершённые станции будут сданы
            if ($stCount > 0 && $passedCnt >= $needed) {
                $r['verdict'] = 'pass';
            } elseif ($stCount > 0 && $possible < $needed) {
                $r['verdict'] = 'fail';
            } else {
                $r['verdict'] = $allDone ? 'fail' : 'pending';
            }
        }
    }
    unset($r);
    return ['stations' => $stations, 'rows' => $rows, 'max_total' => $maxTotal, 'threshold' => $attThreshold,
            'st_count' => $stCount, 'needed' => $needed, 'mode' => $mode];
}

/** Сколько станций нужно сдать при пороге аттестации $threshold% */
function stations_needed(int $stCount, int $threshold): int {
    return $stCount > 0 ? max(1, (int)ceil($stCount * $threshold / 100 - 1e-9)) : 0;
}

/** Правило итога для подписей: «средний % станций не ниже 60%» или «нужно сдать 3 из 5 станций» */
function verdict_rule(array $m): string {
    return $m['mode'] === 'avg'
        ? 'средний % станций не ниже ' . (int)$m['threshold'] . '%'
        : needed_text($m['needed'], $m['st_count']);
}

const VERDICT_MODES = [
    'avg'      => ['Средний процент по станциям', 'Каждая станция переводится в проценты от своего максимума, итог — их среднее: (90 + 55,5 + 60 + 71,3) / 4 = 69,2%. Станции весят одинаково, сколько бы в них ни было критериев.'],
    'stations' => ['Количество сданных станций', 'Станция сдана, если набран её порог. Порог аттестации — доля станций, которые нужно сдать: 100% — все, 60% при 5 станциях — 3.'],
];

function read_verdict_mode(array $src): string {
    return ($src['verdict_mode'] ?? 'avg') === 'stations' ? 'stations' : 'avg';
}

/**
 * Можно ли менять станцию (критерии, порог, удаление).
 * Нельзя, если по ней уже есть оценки, а также если приём аттестуемых начался,
 * а станция существовала до этого. Станцию, добавленную после начала, можно настраивать, пока по ней нет оценок.
 */
function station_locked(PDO $pdo, int $sid): bool {
    $q = $pdo->prepare("SELECT s.attestation_id, s.added_late, (SELECT COUNT(*) FROM results r WHERE r.station_id = s.id) AS res
                        FROM stations s WHERE s.id = ?");
    $q->execute([$sid]);
    $s = $q->fetch();
    if (!$s) return true;
    if ((int)$s['res'] > 0) return true;
    return !(int)$s['added_late'] && att_started($pdo, (int)$s['attestation_id']);
}

/** «нужно сдать 3 из 5 станций» */
function needed_text(int $needed, int $stCount): string {
    if (!$stCount) return 'станций с критериями пока нет';
    return $needed >= $stCount ? 'нужно сдать все станции (' . $stCount . ')' : "нужно сдать $needed из $stCount " . plural_word($stCount, 'станции', 'станций', 'станций');
}

function plural_word(int $n, string $one, string $few, string $many): string {
    $n = abs($n) % 100; $n1 = $n % 10;
    if ($n > 10 && $n < 20) return $many;
    if ($n1 > 1 && $n1 < 5) return $few;
    if ($n1 === 1) return $one;
    return $many;
}

function verdict_badge(string $v, bool $finished = false): string {
    if ($v === 'pass') return '<span class="badge ok">Сдал</span>';
    if ($v === 'fail') return '<span class="badge err">Не сдал</span>';
    return $finished
        ? '<span class="badge err" title="Порог аттестации не набран">Не сдал</span>'
        : '<span class="badge">В процессе</span>';
}

/* ================= Приложение и push: общие карточки ================= */

/** Карточка установки сайта как приложения (кнопку показывает app.js, когда браузер разрешит) */
function install_card(): void {
    ?>
<div class="card feature-card" data-install>
    <div class="feature-head">
        <span class="feature-ic"><?= icon('phone') ?></span>
        <div><b>Приложение на телефоне</b><small data-install-status>Проверяем…</small></div>
    </div>
    <div class="btn-row"><button type="button" class="btn primary" data-install-btn hidden><?= icon('download') ?> Установить приложение</button></div>
    <p class="hint" data-install-hint hidden></p>
</div>
<?php
}

/** Карточка push-уведомлений с выбором разделов */
function push_card(string $back = 'notifications.php'): void {
    global $pdo;
    $_SESSION['push_token'] ??= bin2hex(random_bytes(16));
    $q = $pdo->prepare("SELECT push_off FROM users WHERE id = ?");
    $q->execute([uid()]);
    $off = array_filter(explode(',', (string)$q->fetchColumn()));
    try { $vapid = vapid_keys()['public']; } catch (Throwable $e) { $vapid = ''; }
    ?>
<div class="card feature-card" data-push data-key="<?= e($vapid) ?>" data-api="push_api.php" data-token="<?= e($_SESSION['push_token']) ?>">
    <div class="feature-head">
        <span class="feature-ic"><?= icon('bell') ?></span>
        <div><b>Уведомления на телефон</b><small data-push-status>Проверяем…</small></div>
    </div>
    <div class="btn-row" data-push-actions hidden>
        <button class="btn primary" type="button" data-push-on><?= icon('bell') ?> Включить на этом устройстве</button>
        <button class="btn" type="button" data-push-test hidden><?= icon('send') ?> Проверить</button>
        <button class="btn" type="button" data-push-off hidden>Отключить на этом устройстве</button>
    </div>
    <p class="hint" data-push-hint hidden></p>
    <p class="hint">Xiaomi, Huawei, Honor, Realme: если уведомления приходят с опозданием, разрешите для Chrome (или приложения СМСО) автозапуск, уведомления на экране блокировки и отключите экономию батареи.</p>
    <div class="push-prefs">
        <span class="push-prefs-t">Присылать на телефон:</span>
        <form method="post" action="notifications.php" class="pref-toggles">
            <input type="hidden" name="action" value="push_prefs">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <input type="hidden" name="cats[]" value="__none">
            <?php foreach (PUSH_CATS as $k => $label): ?>
                <label class="pref-toggle"><input type="checkbox" name="cats[]" value="<?= $k ?>" <?= in_array($k, $off, true) ? '' : 'checked' ?> data-autosubmit><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
        </form>
    </div>
</div>
<?php
}

/* ================= Сведения о сайте (публичная страница, политика, контакты) ================= */

/** Поля, которые администратор заполняет на вкладке «О сайте» */
const SITE_FIELDS = [
    'site_org'     => ['Организация', 'Полное название отряда или организации, которая ведёт сайт'],
    'site_about'   => ['О проекте', '2–3 предложения: что это за сайт и для кого'],
    'site_email'   => ['Почта для связи', 'Будет на главной, в подвале страниц и в политике конфиденциальности'],
    'site_link'    => ['Группа или сайт отряда', 'Ссылка, например на группу ВКонтакте или страницу на сайте университета'],
    'site_person'  => ['Ответственный', 'Кто отвечает за сайт и обработку данных (ФИО, должность)'],
];

function site_info(): array {
    static $info = null;
    if ($info !== null) return $info;
    $info = [];
    foreach (array_keys(SITE_FIELDS) as $k) {
        try { $info[$k] = trim((string)(setting_get($k) ?? '')); } catch (Throwable $e) { $info[$k] = ''; }
    }
    return $info;
}

function site_description(): string {
    $i = site_info();
    return $i['site_about'] !== ''
        ? mb_substr($i['site_about'], 0, 300)
        : 'СМСО «Эпсилон» — система проведения аттестации по первой помощи: инструкторы оценивают навыки на станциях, аттестуемые видят свои результаты.';
}

/** Заполнены ли название и хотя бы один контакт — без них сайт выглядит анонимным */
function site_info_complete(): bool {
    $i = site_info();
    return $i['site_org'] !== '' && ($i['site_email'] !== '' || $i['site_link'] !== '');
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** Переадресация на https — включает администратор, когда убедился, что https работает */
function force_https_if_enabled(): void {
    if (PHP_SAPI === 'cli' || is_https()) return;
    try { if (setting_get('site_force_https') !== '1') return; } catch (Throwable $e) { return; }
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return;
    header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

/** Контакты одной строкой (для подвала и страниц) */
function site_contacts_html(): string {
    $i = site_info(); $parts = [];
    if ($i['site_email'] !== '') $parts[] = '<a href="mailto:' . e($i['site_email']) . '">' . e($i['site_email']) . '</a>';
    if ($i['site_link'] !== '' && preg_match('~^https?://~i', $i['site_link'])) {
        $parts[] = '<a href="' . e($i['site_link']) . '" target="_blank" rel="noopener">' . e(preg_replace('~^https?://(www\.)?~i', '', rtrim($i['site_link'], '/'))) . '</a>';
    }
    return implode(' · ', $parts);
}

/** Подвал гостевых страниц: кто ведёт сайт, контакты, политика */
function guest_footer(): void {
    $i = site_info();
    ?>
<footer class="site-footer">
    <div class="sf-inner">
        <div class="sf-brand"><span class="brand-mark">✚</span>
            <div><b>СМСО «Эпсилон»</b><span><?= $i['site_org'] !== '' ? e($i['site_org']) : 'Аттестация по первой помощи' ?></span></div>
        </div>
        <nav class="sf-links">
            <a href="index.php">О проекте</a>
            <a href="privacy.php">Политика конфиденциальности</a>
            <a href="login.php">Вход для инструкторов</a>
            <a href="reg.php">Я аттестуемый</a>
        </nav>
        <?php if ($c = site_contacts_html()): ?><div class="sf-contacts">Связаться: <?= $c ?></div><?php endif; ?>
        <div class="sf-note">Некоммерческий учебный проект. Сайт не принимает платежи и не просит данные банковских карт.</div>
    </div>
</footer>
<?php
}

/* ================= Документооборот (тестовый режим) ================= */
/*
 * Режим (settings.docs_mode): off — выключен (раздел видит только администратор, чтобы настроить),
 *   test — видят администраторы, ответственные за документооборот и отмеченные тестировщики;
 *        в документах пометка «ТЕСТОВЫЙ РЕЖИМ»;
 *   on — видят все, кроме аттестуемых.
 * Ответственные (users.doc_officer) получают заявки, скачивают заполненные документы и отмечают их статус.
 */
const DOCS_MODES = ['off' => 'Выключен', 'test' => 'Тестовый режим', 'on' => 'Включён для всех'];
const DOC_STATUSES = ['new' => ['Новая', 'warn'], 'done' => ['Передана на подпись', 'ok'], 'rejected' => ['Отклонена', 'err'], 'cancelled' => ['Отменена', '']];
/** Поля, которые сайт заполняет сам — заявителя о них не спрашивают */
const DOC_AUTO_FIELDS = ['Заявитель', 'Заявитель кратко', 'Дата заявки', 'Сегодня', 'Номер заявки'];

function docs_mode(): string {
    try { $m = (string)(setting_get('docs_mode') ?? 'test'); } catch (Throwable $e) { $m = 'test'; }
    return isset(DOCS_MODES[$m]) ? $m : 'test';
}

/** Флаги текущего пользователя: [ответственный, тестировщик] */
function doc_flags(): array {
    global $pdo;
    static $f = null;
    if ($f !== null) return $f;
    $f = [false, false];
    if (empty($_SESSION['user_id'])) return $f;
    try {
        $q = $pdo->prepare("SELECT doc_officer, doc_tester FROM users WHERE id = ?");
        $q->execute([uid()]);
        if ($r = $q->fetch()) $f = [(bool)$r['doc_officer'], (bool)$r['doc_tester']];
    } catch (Throwable $e) { /* столбцов ещё нет — база не обновлена */ }
    return $f;
}

function docs_can_use(): bool {
    if (empty($_SESSION['user_id']) || is_participant()) return false;
    if (is_sysadmin()) return true;
    [$officer, $tester] = doc_flags();
    $mode = docs_mode();
    return $mode === 'on' || ($mode === 'test' && ($officer || $tester));
}

/** Обрабатывать заявки: ответственный за документооборот (администратор — тоже, чтобы проверять работу) */
function docs_is_officer(): bool {
    return docs_can_use() && (doc_flags()[0] || is_sysadmin());
}

/** Тип поля ввода по названию поля шаблона */
function doc_field_type(string $name): string {
    $n = mb_strtolower($name);
    // по началу слова: «кандидата» — не дата
    $has = fn(string $w) => (bool)preg_match('/(^|[^\p{L}])' . $w . '/u', $n);
    if ($has('дата') || $has('даты')) return 'date';
    if ($has('время')) return 'time';
    if ($has('телефон') || $has('тел\.')) return 'tel';
    if ($has('почта') || $has('e-?mail')) return 'email';
    foreach (['причин', 'цель', 'комментар', 'обоснован', 'описан', 'текст'] as $w) if ($has($w)) return 'textarea';
    return 'text';
}

/** Значение поля для вставки в документ: даты — в виде 10.11.2026 */
function doc_field_out(string $name, string $v): string {
    $v = trim($v);
    if (doc_field_type($name) === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return "$m[3].$m[2].$m[1]";
    return $v;
}

/** Значения для подстановки: данные заявки + автоматические поля */
function doc_values(array $req, array $fields): array {
    $vals = [];
    $data = json_decode((string)$req['data'], true) ?: [];
    foreach ($fields as $f) if (isset($data[$f])) $vals[$f] = doc_field_out($f, (string)$data[$f]);
    $name = trim((string)$req['user_name']);
    $parts = preg_split('/\s+/u', $name);
    $short = $parts[0] ?? '';
    foreach (array_slice($parts, 1, 2) as $p) if ($p !== '') $short .= ' ' . mb_substr($p, 0, 1) . '.';
    $created = $req['created_at'] ? strtotime((string)$req['created_at']) : time();
    $vals += [
        'Заявитель' => $name,
        'Заявитель кратко' => $short,
        'Дата заявки' => date('d.m.Y', $created),
        'Сегодня' => date('d.m.Y'),
        'Номер заявки' => (string)$req['id'],
    ];
    return $vals;
}

/** Кому уходят новые заявки: ответственные, а если их нет — администраторы */
function doc_officer_ids(PDO $pdo): array {
    $ids = $pdo->query("SELECT id FROM users WHERE doc_officer = 1 AND role <> 'participant'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) $ids = $pdo->query("SELECT id FROM users WHERE role = 'sysadmin'")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $ids);
}

function doc_status_badge(string $s): string {
    [$label, $cls] = DOC_STATUSES[$s] ?? [$s, ''];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}
