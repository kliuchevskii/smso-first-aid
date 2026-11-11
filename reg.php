<?php
// Самостоятельная регистрация и вход аттестуемого.
// По QR-коду (reg.php?code=КОД) аттестация уже известна; по кнопке «Я аттестуемый» код вводят в форме.
// Учётная запись временная: привязана к аттестации и удаляется вместе с ней.
require_once 'functions.php';

if (!empty($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'participant') redirect('me.php');
    flash('Вы вошли как сотрудник. Чтобы зарегистрировать аттестуемого на этом устройстве, сначала выйдите из своей учётной записи.', 'warn');
    redirect('index.php');
}

$code = strtoupper(trim((string)($_POST['code'] ?? $_GET['code'] ?? '')));
$mode = ($_GET['m'] ?? $_POST['mode'] ?? 'reg') === 'login' ? 'login' : 'reg';
$fixed = trim((string)($_GET['code'] ?? '')) !== '';   // пришли по QR-коду/ссылке — код в форме не нужен
$att = null;
if ($code !== '') {
    $q = $pdo->prepare("SELECT * FROM attestations WHERE reg_code = ? AND self_register = 1 AND status <> 'prep'");
    $q->execute([$code]);
    $att = $q->fetch() ?: null;
}
$open = $att && $att['status'] === 'draft';   // регистрация открыта, пока аттестация идёт

$v = ['last' => '', 'first' => '', 'middle' => '', 'gender' => '', 'birth' => ''];
$err = null;

if (is_post()) {
    $v['last']   = normalize_name((string)($_POST['last_name'] ?? ''));
    $v['first']  = normalize_name((string)($_POST['first_name'] ?? ''));
    $v['middle'] = normalize_name((string)($_POST['middle_name'] ?? ''));
    $password    = (string)($_POST['password'] ?? '');
    $nameOk = fn($s) => $s === '' || preg_match("/^[\\p{L}][\\p{L}\\-' ]{0,60}$/u", $s);

    if ($mode === 'login') {
        // вход: фамилия + имя + пароль (код аттестации не нужен; если пришли по QR — ищем только в этой аттестации)
        $aid = $fixed && $att ? (int)$att['id'] : 0;
        $q = $pdo->prepare("SELECT u.* FROM users u JOIN participants p ON p.id = u.participant_id
                            WHERE u.role = 'participant'" . ($aid ? " AND u.attestation_id = $aid" : '') . "
                              AND LOWER(p.last_name) = LOWER(?) AND LOWER(p.first_name) = LOWER(?)");
        $q->execute([$v['last'], $v['first']]);
        foreach ($q->fetchAll() as $u) {
            if (password_verify($password, $u['password_hash'])) {
                login_user($u);
                redirect('me.php');
            }
        }
        $err = 'Не нашли аттестуемого с такими фамилией, именем и паролем';
    } else {
        $v['gender'] = in_array($_POST['gender'] ?? '', ['m', 'f'], true) ? $_POST['gender'] : '';
        $v['birth']  = (string)($_POST['birth_date'] ?? '');
        $bd = DateTime::createFromFormat('!Y-m-d', $v['birth']);
        $minAge = (new DateTime('today'))->modify('-5 years');
        if (!$att) {
            $err = $code === '' ? 'Введите код регистрации — его показывает сотрудник управления под QR-кодом' : 'Код регистрации не подходит. Проверьте его у сотрудника управления';
        } elseif (!$open) {
            $err = 'Регистрация на эту аттестацию закрыта';
        } elseif ($v['last'] === '' || $v['first'] === '') {
            $err = 'Укажите фамилию и имя';
        } elseif (!$nameOk($v['last']) || !$nameOk($v['first']) || !$nameOk($v['middle'])) {
            $err = 'В фамилии, имени и отчестве допустимы только буквы, дефис и пробел';
        } elseif ($v['gender'] === '') {
            $err = 'Укажите пол';
        } elseif (!$bd || $bd->format('Y-m-d') !== $v['birth'] || $bd < new DateTime('1920-01-01') || $bd > $minAge) {
            $err = 'Проверьте дату рождения';
        } elseif (mb_strlen($password) < 6) {
            $err = 'Пароль должен быть не короче 6 символов';
        } elseif ($password !== (string)($_POST['password2'] ?? '')) {
            $err = 'Пароли не совпадают';
        } else {
            $dup = $pdo->prepare("SELECT 1 FROM participants p JOIN attestation_participants ap ON ap.participant_id = p.id
                                  WHERE ap.attestation_id = ? AND LOWER(p.last_name) = LOWER(?) AND LOWER(p.first_name) = LOWER(?)
                                    AND LOWER(COALESCE(p.middle_name, '')) = LOWER(?) AND p.birth_date = ?");
            $dup->execute([$att['id'], $v['last'], $v['first'], $v['middle'], $v['birth']]);
            if ($dup->fetchColumn()) {
                $err = 'Вы уже зарегистрированы в этой аттестации — войдите со своим паролем';
                $mode = 'login';
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO participants (last_name, first_name, middle_name, gender, birth_date) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$v['last'], $v['first'], $v['middle'] ?: null, $v['gender'], $v['birth']]);
                    $pid = (int)$pdo->lastInsertId();
                    $num = roster_add($pdo, (int)$att['id'], $pid);
                    $fio = trim("{$v['last']} {$v['first']} {$v['middle']}");
                    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, attestation_id, participant_id) VALUES (?, ?, ?, 'participant', ?, ?)")
                        ->execute(['p' . $att['id'] . '-' . $pid, password_hash($password, PASSWORD_DEFAULT), $fio, $att['id'], $pid]);
                    $uid = (int)$pdo->lastInsertId();
                    $pdo->prepare("UPDATE participants SET user_id = ? WHERE id = ?")->execute([$uid, $pid]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                $u = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $u->execute([$uid]);
                login_user($u->fetch());
                flash('Вы зарегистрированы. Ваш номер — №' . $num . ': назовите его инструктору на станции.');
                redirect('me.php');
            }
        }
    }
}

page_start($att ? 'Аттестуемый · ' . $att['title'] : 'Вход для аттестуемых', ['width' => 'narrow', 'bare' => true]);
?>
<div class="auth auth-wide">
    <div class="auth-brand"><span class="brand-mark big">✚</span>
        <div><b>СМСО «Эпсилон»</b><div class="muted small">Аттестация по первой помощи</div></div>
    </div>
    <div class="card">
        <?php if ($err): ?><div class="alert err" role="alert"><?= e($err) ?></div><?php endif; ?>

        <?php if ($fixed && $att): ?>
            <div class="reg-att">
                <span class="muted small">Аттестация</span>
                <b><?= e($att['title']) ?></b>
                <span class="badge <?= status_class($att['status']) ?>"><?= e(status_label($att['status'])) ?></span>
            </div>
        <?php else: ?>
            <h1 class="mt0">Я аттестуемый</h1>
            <p class="muted mt0">Зарегистрируйтесь на аттестацию или войдите, если уже регистрировались.</p>
        <?php endif; ?>
        <?php $q = $fixed ? 'code=' . urlencode($code) . '&' : ''; ?>
        <nav class="seg-tabs reg-tabs">
            <a class="<?= $mode === 'reg' ? 'is-on' : '' ?>" href="reg.php<?= $fixed ? '?code=' . urlencode($code) : '' ?>">Регистрация</a>
            <a class="<?= $mode === 'login' ? 'is-on' : '' ?>" href="reg.php?<?= $q ?>m=login">Вход</a>
        </nav>

            <?php if ($mode === 'reg' && $fixed && $att && !$open): ?>
                <div class="alert warn">Регистрация закрыта: аттестация уже завершена. Если вы регистрировались раньше — войдите.</div>
            <?php elseif ($mode === 'reg'): ?>
                <form method="post" class="stack reg-form" autocomplete="off" action="reg.php<?= $fixed ? '?code=' . urlencode($code) : '' ?>">
                    <input type="hidden" name="mode" value="reg">
                    <?php if ($fixed && $att): ?>
                        <input type="hidden" name="code" value="<?= e($code) ?>">
                    <?php else: ?>
                        <label>Код регистрации
                            <span class="code-field">
                                <input name="code" id="reg-code" class="code-input" value="<?= e($code) ?>" placeholder="КОД" maxlength="16" autocomplete="off" required>
                                <button type="button" class="scan-btn" data-scan="#scan-dialog" data-scan-target="#reg-code" data-scan-fill title="Сканировать QR-код" aria-label="Сканировать QR-код"><?= icon('qr') ?></button>
                            </span>
                        </label>
                        <p class="muted small mt0">Код показывает сотрудник управления под QR-кодом регистрации. Можно отсканировать QR-код кнопкой справа.</p>
                    <?php endif; ?>
                    <p class="muted small mt0">Пишите полностью и без ошибок — так вы будете указаны в результатах аттестации.</p>
                    <label>Фамилия <input name="last_name" value="<?= e($v['last']) ?>" required maxlength="60" autocapitalize="words"></label>
                    <label>Имя <input name="first_name" value="<?= e($v['first']) ?>" required maxlength="60" autocapitalize="words"></label>
                    <label>Отчество <span class="muted small">(если есть)</span> <input name="middle_name" value="<?= e($v['middle']) ?>" maxlength="60" autocapitalize="words"></label>
                    <fieldset class="gender-field">
                        <legend>Пол</legend>
                        <div class="pref-toggles">
                            <label class="pref-toggle"><input type="radio" name="gender" value="m" <?= $v['gender'] === 'm' ? 'checked' : '' ?> required><span>Мужской</span></label>
                            <label class="pref-toggle"><input type="radio" name="gender" value="f" <?= $v['gender'] === 'f' ? 'checked' : '' ?>><span>Женский</span></label>
                        </div>
                    </fieldset>
                    <label>Дата рождения <input type="date" name="birth_date" value="<?= e($v['birth']) ?>" min="1920-01-01" max="<?= date('Y-m-d') ?>" required></label>
                    <div class="grid-2">
                        <label>Придумайте пароль <input type="password" name="password" minlength="6" autocomplete="new-password" required></label>
                        <label>Повторите пароль <input type="password" name="password2" minlength="6" autocomplete="new-password" required></label>
                    </div>
                    <p class="muted small mt0">Пароль нужен, чтобы снова открыть свои результаты. Учётная запись действует только на время этой аттестации и удаляется вместе с ней.</p>
                    <p class="muted small consent">Нажимая «Зарегистрироваться», вы соглашаетесь с обработкой данных для проведения аттестации — <a href="privacy.php">политика конфиденциальности</a>.</p>
                    <button class="btn primary full"><?= icon('check') ?> Зарегистрироваться</button>
                </form>
            <?php else: ?>
                <form method="post" class="stack" action="reg.php?<?= $q ?>m=login">
                    <?php if ($fixed): ?><input type="hidden" name="code" value="<?= e($code) ?>"><?php endif; ?>
                    <input type="hidden" name="mode" value="login">
                    <label>Фамилия <input name="last_name" value="<?= e($v['last']) ?>" required autocapitalize="words"></label>
                    <label>Имя <input name="first_name" value="<?= e($v['first']) ?>" required autocapitalize="words"></label>
                    <label>Пароль <input type="password" name="password" autocomplete="current-password" required></label>
                    <button class="btn primary full">Войти</button>
                </form>
                <p class="muted small center">Забыли пароль? Подойдите к сотруднику управления — он выдаст временный пароль, результаты сохранятся.</p>
            <?php endif; ?>
        <p class="center muted small reg-staff">Инструктор или сотрудник управления? <a href="login.php">Обычный вход</a></p>
    </div>
</div>

<dialog class="modal scan-modal" id="scan-dialog">
    <div class="modal-head">
        <h2 class="mt0">Сканер QR-кода</h2>
        <button type="button" class="icon-btn" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button>
    </div>
    <div class="scan-view"><video muted playsinline></video><span class="scan-frame"></span></div>
    <p class="muted small center" data-scan-status>Запускаем камеру…</p>
    <div class="modal-actions"><button type="button" class="btn" data-dialog-close>← Назад</button></div>
</dialog>
<?php page_end();
