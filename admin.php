<?php
// Кабинет администратора: учётные записи, роли, коды доступа, сброс паролей.
// Раздел виден только администратору; в остальном администратор работает как сотрудник управления.
define('SYSADMIN_PAGE', true);
require_once 'functions.php';
require_login();
if (!is_sysadmin()) redirect('index.php');

/**
 * Удаление учётной записи.
 * Личные записи (членство, назначения на станции, уведомления, запросы сброса) удаляются.
 * Ссылки «кто создал / кто оценил» не удаляются вместе с данными: они обнуляются, а если столбец
 * не допускает пустого значения — переписываются на администратора, который удаляет запись.
 * Внешние ключи, которые могли быть созданы в базе раньше, находятся автоматически.
 */
function delete_user_account(PDO $pdo, int $userId, int $heirId): void {
    $personal = [
        ['station_judges', 'user_id'], ['attestation_members', 'user_id'], ['password_resets', 'user_id'],
        ['notifications', 'user_id'], ['role_code_uses', 'user_id'], ['lesson_progress', 'user_id'], ['lesson_done', 'user_id'],
    ];
    $authorship = [
        ['results', 'instructor_id'], ['stations', 'created_by'], ['attestations', 'created_by'],
        ['role_codes', 'created_by'], ['courses', 'created_by'], ['courses', 'updated_by'],
        ['lessons', 'created_by'], ['lessons', 'updated_by'],
    ];
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($isMysql) {
        // все внешние ключи, которые ссылаются на users.id
        $q = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                          WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id'");
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
            $known = false;
            foreach (array_merge($personal, $authorship) as [$kt, $kc]) {
                if (strcasecmp($kt, $t) === 0 && strcasecmp($kc, $c) === 0) $known = true;
            }
            if (!$known) $authorship[] = [$t, $c];
        }
    }
    $exists = function (string $t, string $c) use ($pdo, $isMysql): bool {
        if (!$isMysql) return true;
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $q->execute([$t, $c]);
        return (int)$q->fetchColumn() > 0;
    };

    $pdo->beginTransaction();
    try {
        foreach ($personal as [$t, $c]) {
            if ($exists($t, $c)) $pdo->prepare("DELETE FROM `$t` WHERE `$c` = ?")->execute([$userId]);
        }
        foreach ($authorship as [$t, $c]) {
            if (!$exists($t, $c)) continue;
            try {
                $pdo->prepare("UPDATE `$t` SET `$c` = NULL WHERE `$c` = ?")->execute([$userId]);
            } catch (PDOException $e) {
                // столбец NOT NULL — передаём авторство администратору
                $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?")->execute([$heirId, $userId]);
            }
        }
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function gen_code(int $len = 8): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

/** Куда вернуться после действия с человеком: карточка откроется снова на нужной вкладке */
function back_to_user(int $id, string $tab): string {
    $q = $_POST['ret'] ?? '';
    $q = preg_match('/^[a-z0-9_=&%.\-+]*$/i', $q) ? $q : '';
    return 'admin.php?' . ($q !== '' ? $q . '&' : '') . "u=$id&ut=$tab#users";
}

if (is_post()) {
    $action = $_POST['action'] ?? '';
    $u = (int)($_POST['user'] ?? 0);
    $go = 'admin.php#users';

    $target = null;
    if ($u) {
        $t = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $t->execute([$u]);
        $target = $t->fetch() ?: null;
    }
    $sysCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'sysadmin'")->fetchColumn();

    // ---- новая учётная запись ----
    if ($action === 'create_user') {
        $fio   = trim(preg_replace('/\s+/u', ' ', $_POST['full_name'] ?? ''));
        $login = strtolower(trim($_POST['username'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['instructor', 'admin', 'sysadmin'], true) ? $_POST['role'] : 'instructor';
        $dup = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(username) = ? OR (? <> '' AND LOWER(email) = LOWER(?))");
        $dup->execute([$login, $email, $email]);
        if (count(preg_split('/\s+/u', $fio)) < 2) {
            flash('Укажите фамилию, имя и отчество', 'err');
        } elseif (!preg_match('/^[a-z0-9_.\-]{3,32}$/', $login)) {
            flash('Логин — от 3 до 32 латинских букв, цифр, точек, дефисов', 'err');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Проверьте адрес почты', 'err');
        } elseif ($dup->fetchColumn()) {
            flash('Такой логин или почта уже заняты', 'err');
        } else {
            $tmp = gen_code(8);
            $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, role, must_change_password, temp_password) VALUES (?, ?, ?, ?, ?, 1, ?)")
                ->execute([$login, password_hash($tmp, PASSWORD_DEFAULT), $fio, $email ?: null, $role, $tmp]);
            $newId = (int)$pdo->lastInsertId();
            flash("Учётная запись создана. Логин $login, временный пароль $tmp — передайте лично, после входа сайт попросит его сменить.", 'warn');
            redirect("admin.php?u=$newId&ut=access#users");
        }
        redirect('admin.php#users');
    }

    // ---- данные человека ----
    if ($target && $action === 'save_user') {
        $fio   = trim(preg_replace('/\s+/u', ' ', $_POST['full_name'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $login = strtolower(trim($_POST['username'] ?? ''));
        $dup = $pdo->prepare("SELECT 1 FROM users WHERE id <> ? AND (LOWER(username) = ? OR (? <> '' AND LOWER(email) = LOWER(?)))");
        $dup->execute([$u, $login, $email, $email]);
        if (count(preg_split('/\s+/u', $fio)) < 2) {
            flash('Укажите фамилию, имя и отчество', 'err');
        } elseif (!preg_match('/^[a-z0-9_.\-]{3,32}$/', $login)) {
            flash('Логин — от 3 до 32 латинских букв, цифр, точек, дефисов', 'err');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Проверьте адрес почты', 'err');
        } elseif ($dup->fetchColumn()) {
            flash('Такой логин или почта уже заняты другой учётной записью', 'err');
        } else {
            $pdo->prepare("UPDATE users SET full_name = ?, email = ?, username = ? WHERE id = ?")->execute([$fio, $email ?: null, $login, $u]);
            if ($u === uid()) $_SESSION['full_name'] = $fio;
            flash('Данные сохранены');
        }
        redirect(back_to_user($u, 'main'));
    }

    // ---- роль ----
    if ($target && $action === 'set_role') {
        $newRole = $_POST['role'] ?? '';
        if ($target['role'] === 'participant') {
            flash('У аттестуемого временная учётная запись — роль ему не меняется', 'err');
        } elseif (!in_array($newRole, ['instructor', 'admin', 'sysadmin'], true)) {
            flash('Неизвестная роль', 'err');
        } elseif ($target['role'] === 'sysadmin' && $newRole !== 'sysadmin' && $sysCount <= 1) {
            flash('Нельзя снять последнего администратора', 'err');
        } elseif ($u === uid() && $newRole !== 'sysadmin') {
            flash('Свою роль администратора снять нельзя — попросите другого администратора', 'err');
        } elseif ($newRole !== $target['role']) {
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $u]);
            notify_role($pdo, $u, $newRole);
            flash($target['full_name'] . ': теперь ' . mb_strtolower(role_name($newRole)));
        }
        redirect(back_to_user($u, 'role'));
    }

    // ---- документооборот: ответственный и тестировщик ----
    if ($target && $action === 'set_docs') {
        if ($target['role'] === 'participant') {
            flash('Аттестуемому раздел «Документы» недоступен', 'err');
        } else {
            $off = empty($_POST['doc_officer']) ? 0 : 1;
            $tst = empty($_POST['doc_tester']) ? 0 : 1;
            $pdo->prepare("UPDATE users SET doc_officer = ?, doc_tester = ? WHERE id = ?")->execute([$off, $tst, $u]);
            if ($off && !(int)($target['doc_officer'] ?? 0)) {
                notify($pdo, $u, 'Вас назначили ответственным за документооборот — заявки на документы в разделе «Документы»', 'docs.php', 'doc');
            }
            flash($target['full_name'] . ': ' . ($off ? 'ответственный за документооборот' : ($tst ? 'тестирует «Документы»' : 'без доступа к тестовому разделу «Документы»')));
        }
        redirect(back_to_user($u, 'role'));
    }

    if ($target && $action === 'reset_password') {
        $tmp = gen_code(8);
        // временный пароль виден администратору, пока пользователь не сменит его на постоянный
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, temp_password = ? WHERE id = ?")
            ->execute([password_hash($tmp, PASSWORD_DEFAULT), $tmp, $u]);
        $pdo->prepare("UPDATE password_resets SET status = 'done', done_at = ? WHERE user_id = ? AND status = 'pending'")
            ->execute([date('Y-m-d H:i:s'), $u]);
        flash("Временный пароль для {$target['full_name']} (логин {$target['username']}): $tmp — передайте его лично. После входа сайт попросит сменить пароль.", 'warn');
        redirect(($_POST['tab'] ?? '') === 'resets' ? 'admin.php#resets' : back_to_user($u, 'access'));
    }

    if ($target && $action === 'dismiss_reset') {
        $pdo->prepare("UPDATE password_resets SET status = 'dismissed', done_at = ? WHERE user_id = ? AND status = 'pending'")
            ->execute([date('Y-m-d H:i:s'), $u]);
        redirect('admin.php#resets');
    }

    if ($target && $action === 'delete_user') {
        if ($u === uid()) {
            flash('Нельзя удалить свою учётную запись', 'err');
        } elseif ($target['role'] === 'sysadmin' && $sysCount <= 1) {
            flash('Нельзя удалить последнего администратора', 'err');
        } else {
            try {
                if ($target['role'] === 'participant' && $target['participant_id']) {
                    // аттестуемый: убираем и его запись в аттестации вместе с результатами
                    $ppid = (int)$target['participant_id'];
                    $pdo->prepare("DELETE FROM results WHERE participant_id = ?")->execute([$ppid]);
                    $pdo->prepare("DELETE FROM attestation_participants WHERE participant_id = ?")->execute([$ppid]);
                    $pdo->prepare("DELETE FROM participants WHERE id = ?")->execute([$ppid]);
                }
                delete_user_account($pdo, $u, uid());
                flash("Учётная запись {$target['full_name']} удалена. Выставленные им оценки сохранены.", 'warn');
            } catch (PDOException $e) {
                flash('Не удалось удалить учётную запись: ' . $e->getMessage(), 'err');
            }
        }
        redirect('admin.php#users');
    }

    if ($action === 'save_site') {
        foreach (array_keys(SITE_FIELDS) as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($k === 'site_email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { flash('Проверьте адрес почты', 'err'); redirect('admin.php#site'); }
            if ($k === 'site_link' && $v !== '' && !preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
            setting_set($k, mb_substr($v, 0, $k === 'site_about' ? 600 : 200));
        }
        $https = !empty($_POST['site_force_https']) ? '1' : '0';
        if ($https === '1' && !is_https()) {
            flash('Сведения сохранены. Переадресацию на https можно включить, только открыв эту страницу по https:// — так сайт проверит, что защищённый адрес работает.', 'warn');
            setting_set('site_force_https', '0');
        } else {
            setting_set('site_force_https', $https);
            flash('Сведения о сайте сохранены');
        }
        redirect('admin.php#site');
    }

    if ($action === 'create_code') {
        $go = 'admin.php#codes';
        $code = strtoupper(trim($_POST['code'] ?? '')) ?: gen_code(6);
        $uses = max(0, (int)($_POST['max_uses'] ?? 1));
        if (!preg_match('/^[A-Z0-9]{4,32}$/', $code) || $code === SYSADMIN_CODE) {
            flash('Код — от 4 до 32 латинских букв и цифр', 'err');
        } else {
            $ex = $pdo->prepare("SELECT 1 FROM role_codes WHERE code = ?");
            $ex->execute([$code]);
            if ($ex->fetchColumn()) {
                flash('Такой код уже есть', 'err');
            } else {
                $pdo->prepare("INSERT INTO role_codes (code, role, note, max_uses, created_by) VALUES (?, 'admin', ?, ?, ?)")
                    ->execute([$code, trim($_POST['note'] ?? '') ?: null, $uses, uid()]);
                flash("Код $code создан");
            }
        }
    }

    if ($action === 'clear_code_history') {
        // убрать историю: записи об использовании и исчерпанные коды; действующие коды остаются
        $go = 'admin.php#codes';
        $pdo->exec("DELETE FROM role_code_uses");
        $n = $pdo->exec("DELETE FROM role_codes WHERE max_uses > 0 AND used_count >= max_uses");
        flash('История выдачи кодов очищена' . ($n ? ", удалено исчерпанных кодов: $n" : ''), 'warn');
    }

    if ($action === 'delete_code') {
        $go = 'admin.php#codes';
        $pdo->prepare("DELETE FROM role_codes WHERE id = ?")->execute([(int)($_POST['code_id'] ?? 0)]);
        flash('Код удалён', 'warn');
    }
    redirect($go);
}

/* ---------- список людей: поиск, фильтры, сортировка (как в itmo.sail) ---------- */
$qstr = trim((string)($_GET['q'] ?? ''));
$fRole = (string)($_GET['role'] ?? '');
$fPass = (string)($_GET['pass'] ?? '');
$sort  = (string)($_GET['sort'] ?? 'role');
$SORTS = ['role' => 'По роли', 'az' => 'ФИО: А → Я', 'za' => 'ФИО: Я → А', 'new' => 'Сначала новые', 'atts' => 'По числу аттестаций'];
if (!isset($SORTS[$sort])) $sort = 'role';
if (!in_array($fRole, ['', 'sysadmin', 'admin', 'instructor', 'participant'], true)) $fRole = '';
if (!in_array($fPass, ['', 'temp', 'reset'], true)) $fPass = '';

$all = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM attestation_members m WHERE m.user_id = u.id AND m.status = 'active') AS att_cnt,
           (SELECT COUNT(*) FROM attestations a WHERE a.created_by = u.id) AS own_cnt,
           (SELECT COUNT(*) FROM password_resets r WHERE r.user_id = u.id AND r.status = 'pending') AS reset_cnt,
           pa.title AS part_att_title, pa.status AS part_att_status
    FROM users u LEFT JOIN attestations pa ON pa.id = u.attestation_id AND u.role = 'participant'
")->fetchAll();
$counts = array_count_values(array_column($all, 'role'));
$users = array_values(array_filter($all, function ($x) use ($qstr, $fRole, $fPass) {
    if ($qstr !== '') {
        $hay = mb_strtolower($x['full_name'] . ' ' . $x['username'] . ' ' . ($x['email'] ?? '') . ' ' . ($x['part_att_title'] ?? ''));
        if (!str_contains($hay, mb_strtolower($qstr))) return false;
    }
    if ($fRole !== '' && $x['role'] !== $fRole) return false;
    if ($fPass === 'temp' && !(int)$x['must_change_password']) return false;
    if ($fPass === 'reset' && !(int)$x['reset_cnt']) return false;
    return true;
}));
$roleOrd = ['sysadmin' => 0, 'admin' => 1, 'instructor' => 2, 'participant' => 3];
$byName = fn($a, $b) => strcmp(mb_strtolower($a['full_name']), mb_strtolower($b['full_name']));
usort($users, match ($sort) {
    'az'   => $byName,
    'za'   => fn($a, $b) => -$byName($a, $b),
    'new'  => fn($a, $b) => (int)$b['id'] <=> (int)$a['id'],
    'atts' => fn($a, $b) => ((int)$b['att_cnt'] + (int)$b['own_cnt']) <=> ((int)$a['att_cnt'] + (int)$a['own_cnt']) ?: $byName($a, $b),
    default => fn($a, $b) => ($roleOrd[$a['role']] ?? 9) <=> ($roleOrd[$b['role']] ?? 9) ?: $byName($a, $b),
});
$filtered = $qstr !== '' || $fRole !== '' || $fPass !== '' || $sort !== 'role';
$retQs = http_build_query(array_filter(['q' => $qstr, 'role' => $fRole, 'pass' => $fPass, 'sort' => $sort !== 'role' ? $sort : '']));

$codes = $pdo->query("SELECT * FROM role_codes ORDER BY id DESC")->fetchAll();
$usedBy = [];
foreach ($pdo->query("SELECT cu.code_id, u.full_name FROM role_code_uses cu JOIN users u ON u.id = cu.user_id ORDER BY cu.id") as $r) {
    $usedBy[(int)$r['code_id']][] = $r['full_name'];
}
foreach ($codes as &$c) $c['used_by'] = isset($usedBy[(int)$c['id']]) ? implode(', ', $usedBy[(int)$c['id']]) : null;
unset($c);

$resets = $pdo->query("
    SELECT r.user_id, MIN(r.created_at) AS created_at, u.full_name, u.username, u.email
    FROM password_resets r JOIN users u ON u.id = r.user_id
    WHERE r.status = 'pending' AND r.via = 'admin'
    GROUP BY r.user_id, u.full_name, u.username, u.email
    ORDER BY created_at
")->fetchAll();

$roleBadge = ['sysadmin' => 'gold', 'admin' => 'info', 'instructor' => '', 'participant' => 'silver'];
page_start('Администрирование', ['section' => 'admin']);
?>
<section class="page-head">
    <div>
        <h1>Администрирование</h1>
        <div class="muted">Учётные записи, роли, коды доступа и сброс паролей.</div>
    </div>
</section>

<nav class="seg-tabs" data-tabs>
    <a href="#users" data-tab="users">Пользователи</a>
    <a href="#codes" data-tab="codes">Коды доступа</a>
    <a href="#resets" data-tab="resets">Сброс пароля<?php if ($resets): ?> <span class="count warn"><?= count($resets) ?></span><?php endif; ?></a>
    <a href="#site" data-tab="site">О сайте<?php if (!site_info_complete()): ?> <span class="count warn">!</span><?php endif; ?></a>
</nav>

<!-- ===== Пользователи ===== -->
<section class="tab-panel" id="users">
    <div class="adm-cols">
        <div class="adm-main">
            <form class="card ufilters" method="get" action="admin.php#users">
                <div class="usearch">
                    <input type="search" name="q" value="<?= e($qstr) ?>" placeholder="Поиск: ФИО, логин или почта" aria-label="Поиск">
                    <button class="btn primary">Найти</button>
                </div>
                <div class="fgrid">
                    <label>Роль
                        <select name="role" data-autosubmit>
                            <option value="">Все</option>
                            <?php foreach (['sysadmin', 'admin', 'instructor', 'participant'] as $r): ?>
                                <option value="<?= $r ?>" <?= $fRole === $r ? 'selected' : '' ?>><?= e(role_name($r)) ?> (<?= $counts[$r] ?? 0 ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Пароль
                        <select name="pass" data-autosubmit>
                            <option value="">Все</option>
                            <option value="temp" <?= $fPass === 'temp' ? 'selected' : '' ?>>Временный, не сменён</option>
                            <option value="reset" <?= $fPass === 'reset' ? 'selected' : '' ?>>Просят сбросить</option>
                        </select>
                    </label>
                    <label class="fsort">Сортировка
                        <select name="sort" data-autosubmit>
                            <?php foreach ($SORTS as $k => $v): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="ffoot">
                    <span class="muted small">Найдено: <b><?= count($users) ?></b> из <?= count($all) ?></span>
                    <?php if ($filtered): ?><a class="btn small" href="admin.php#users"><?= icon('x') ?> Сбросить</a><?php endif; ?>
                </div>
            </form>

            <div class="card ulist">
                <div class="ulist-head"><span>Пользователь</span><span>Роль</span><span>Аттестаций</span><span>Пароль</span></div>
                <?php foreach ($users as $x): $self = (int)$x['id'] === uid(); ?>
                    <button type="button" class="urow <?= $x['role'] === 'participant' ? 'is-part' : '' ?>" data-user-card="<?= (int)$x['id'] ?>">
                        <span class="u-main">
                            <span class="avatar sm"><?= e(initials($x['full_name'])) ?></span>
                            <span class="u-name"><b><?= e($x['full_name']) ?><?= $self ? ' <span class="muted small">(вы)</span>' : '' ?></b>
                                <?php if ($x['role'] === 'participant'): ?>
                                    <small class="part-att"><?= icon('list') ?> <?= $x['part_att_title'] !== null ? '«' . e($x['part_att_title']) . '» · ' . e(mb_strtolower(status_label($x['part_att_status']))) : 'аттестация удалена' ?></small>
                                <?php else: ?>
                                    <small>@<?= e($x['username']) ?><?= $x['email'] ? ' · ' . e($x['email']) : '' ?></small>
                                <?php endif; ?></span>
                        </span>
                        <span class="u-c"><span class="badge <?= $roleBadge[$x['role']] ?? '' ?>"><?= e(role_name($x['role'])) ?></span></span>
                        <span class="u-c" data-label="Аттестаций"><?= (int)$x['att_cnt'] + (int)$x['own_cnt'] ?: '<span class="muted">—</span>' ?></span>
                        <span class="u-c">
                            <?php if ((int)$x['reset_cnt']): ?><span class="badge warn">просит сброс</span>
                            <?php elseif ((int)$x['must_change_password']): ?><span class="badge info">временный</span>
                            <?php else: ?><span class="muted">—</span><?php endif; ?>
                        </span>
                    </button>
                <?php endforeach; ?>
                <?php if (!$users): ?><p class="muted pad">Никого не найдено.</p><?php endif; ?>
            </div>
        </div>

        <aside class="adm-side">
            <details class="card add-user" <?= count($all) <= 1 ? 'open' : '' ?>>
                <summary><?= icon('plus') ?> Добавить пользователя</summary>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="create_user">
                    <label>Фамилия Имя Отчество <input name="full_name" required></label>
                    <label>Логин <input name="username" pattern="[A-Za-z0-9_.\-]{3,32}" autocomplete="off" required></label>
                    <label>Почта <span class="muted small">(необязательно)</span> <input type="email" name="email"></label>
                    <label>Роль
                        <select name="role">
                            <option value="instructor">Инструктор</option>
                            <option value="admin">Сотрудник управления</option>
                            <option value="sysadmin">Администратор</option>
                        </select>
                    </label>
                    <p class="muted small mt0">Временный пароль появится после сохранения — передайте его лично.</p>
                    <button class="btn primary block"><?= icon('plus') ?> Добавить</button>
                </form>
            </details>
            <div class="card role-legend">
                <div class="legend-row"><span class="badge gold">Администратор</span><span class="muted small">всё, что управление, плюс учётные записи, роли и коды</span></div>
                <div class="legend-row"><span class="badge info">Сотрудник управления</span><span class="muted small">ведёт аттестации, судит как инструктор</span></div>
                <div class="legend-row"><span class="badge">Инструктор</span><span class="muted small">оценивает на станциях, проходит курсы</span></div>
                <div class="legend-row"><span class="badge silver">Аттестуемый</span><span class="muted small">зарегистрировался сам на одну аттестацию; учётная запись удалится вместе с ней</span></div>
            </div>
        </aside>
    </div>
</section>

<dialog class="modal user-modal" id="user-modal" data-src="admin_user.php" data-ret="<?= e($retQs) ?>"
        data-open-user="<?= (int)($_GET['u'] ?? 0) ?: '' ?>" data-open-tab="<?= e((string)($_GET['ut'] ?? '')) ?>">
    <div data-modal-body><p class="muted pad">Загрузка…</p></div>
</dialog>

<!-- ===== Коды ===== -->
<section class="tab-panel" id="codes">
    <div class="card">
        <h2 class="mt0">Новый код сотрудника управления</h2>
        <p class="muted small">Код вводят при регистрации (поле «У меня есть код доступа») или в профиле на вкладке «Полномочия» — и человек сразу становится сотрудником управления. Можно выдать личный одноразовый код конкретному человеку.</p>
        <form method="post" class="code-form">
            <input type="hidden" name="action" value="create_code">
            <label>Код <input name="code" class="code-input" placeholder="авто" maxlength="32"></label>
            <label>Использований <input type="number" name="max_uses" value="1" min="0" title="0 — без ограничений"></label>
            <label>Для кого / заметка <input name="note" placeholder="Например: Петров П. П."></label>
            <button class="btn primary"><?= icon('plus') ?> Создать</button>
        </form>
        <p class="muted small">0 использований — код без ограничений. Код администратора задан в программе и здесь не показывается.</p>
    </div>
    <?php if ($codes): ?>
        <div class="section-head">
            <h2 class="mt0">Выданные коды</h2>
            <form method="post" class="inline">
                <input type="hidden" name="action" value="clear_code_history">
                <button class="btn small ghost danger" data-confirm="Очистить историю выдачи кодов? Исчерпанные коды и записи о том, кто ими воспользовался, будут удалены. Действующие коды останутся."><?= icon('trash') ?> Очистить историю</button>
            </form>
        </div>
    <?php endif; ?>
    <?php if (!$codes): ?>
        <div class="empty">Кодов пока нет.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="simple card-table codes-table">
                <thead><tr><th>Код</th><th>Заметка</th><th>Использован</th><th>Кем</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($codes as $c): $left = (int)$c['max_uses'] === 0 || (int)$c['used_count'] < (int)$c['max_uses']; ?>
                    <tr>
                        <td class="ct-title"><button type="button" class="code-chip small" data-copy="<?= e($c['code']) ?>"><?= e($c['code']) ?></button></td>
                        <td data-label="Заметка"><?= e($c['note'] ?? '') ?></td>
                        <td data-label="Использован"><?= (int)$c['used_count'] ?> / <?= (int)$c['max_uses'] ?: '∞' ?>
                            <?= $left ? '<span class="badge ok">действует</span>' : '<span class="badge">исчерпан</span>' ?></td>
                        <td class="small" data-label="Кем"><?= e($c['used_by'] ?? '—') ?></td>
                        <td class="row-actions">
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="delete_code">
                                <input type="hidden" name="code_id" value="<?= (int)$c['id'] ?>">
                                <button class="icon-btn danger" title="Удалить" data-confirm="Удалить код <?= e($c['code']) ?>?"><?= icon('trash') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ===== Сброс пароля ===== -->
<section class="tab-panel" id="site">
    <?php $si = site_info(); $forced = setting_get('site_force_https') === '1'; ?>
    <?php if (!site_info_complete()): ?>
        <div class="alert warn">Заполните название организации и хотя бы один контакт. Без них публичная главная и политика конфиденциальности выглядят анонимно — по этому признаку браузеры принимают сайты за фишинговые.</div>
    <?php endif; ?>
    <div class="card">
        <h2 class="mt0">Сведения о сайте</h2>
        <p class="muted small">Показываются на публичной главной, в подвале страниц входа и регистрации и в <a href="privacy.php" target="_blank">политике конфиденциальности</a>.</p>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="save_site">
            <?php foreach (SITE_FIELDS as $k => [$label, $hint]): ?>
                <label><?= e($label) ?> <span class="muted small">— <?= e($hint) ?></span>
                    <?php if ($k === 'site_about'): ?>
                        <textarea name="<?= $k ?>" rows="3" maxlength="600"><?= e($si[$k]) ?></textarea>
                    <?php else: ?>
                        <input name="<?= $k ?>" value="<?= e($si[$k]) ?>" <?= $k === 'site_email' ? 'type="email"' : '' ?> maxlength="200">
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            <label class="switch-row">
                <input type="checkbox" name="site_force_https" value="1" <?= $forced ? 'checked' : '' ?>>
                <span><b>Всегда открывать сайт по https://</b>
                    <small class="muted"><?= is_https()
                        ? 'Сейчас страница открыта по https — значит, защищённый адрес работает и переадресацию можно включать.'
                        : 'Сейчас страница открыта по http. Сначала откройте сайт по https:// — если он открывается без ошибок, включите переадресацию оттуда.' ?></small></span>
            </label>
            <div class="btn-row">
                <button class="btn primary"><?= icon('save') ?> Сохранить</button>
                <a class="btn" href="landing.php" target="_blank"><?= icon('eye') ?> Публичная главная</a>
                <a class="btn" href="privacy.php" target="_blank"><?= icon('eye') ?> Политика</a>
            </div>
        </form>
    </div>
</section>

<section class="tab-panel" id="resets">
    <?php if (!$resets): ?>
        <div class="empty">Запросов на сброс пароля нет.</div>
    <?php else: ?>
        <div class="card ulist">
            <?php foreach ($resets as $r): ?>
                <div class="urow static">
                    <span class="u-main">
                        <span class="avatar sm"><?= e(initials($r['full_name'])) ?></span>
                        <span class="u-name"><b><?= e($r['full_name']) ?></b><small>@<?= e($r['username']) ?><?= $r['email'] ? ' · ' . e($r['email']) : '' ?> · <?= e(date('d.m.Y H:i', strtotime($r['created_at']))) ?></small></span>
                    </span>
                    <form method="post" class="btn-row reset-actions">
                        <input type="hidden" name="user" value="<?= (int)$r['user_id'] ?>">
                        <input type="hidden" name="tab" value="resets">
                        <button name="action" value="reset_password" class="btn small primary">Выдать временный пароль</button>
                        <button name="action" value="dismiss_reset" class="btn small ghost">Отклонить</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php page_end();
