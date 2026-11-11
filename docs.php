<?php
/*
 * Раздел «Документы» — электронный документооборот (тестовый режим).
 *   Заказать      — шаблоны документов; заявитель заполняет поля шаблона и отправляет заявку;
 *   Мои заявки    — статус своих заявок;
 *   Заявки        — для ответственных: заявки с данными, скачивание заполненного .docx, статус;
 *   Шаблоны       — для ответственных: загрузка .docx с полями {Название поля};
 *   Настройки     — для администратора: режим раздела, кто ответственный и кто тестирует.
 */
require_once 'functions.php';
require_once 'docx.php';
require_login();
if (!docs_can_use()) { flash('Раздел «Документы» пока недоступен', 'err'); redirect('index.php'); }

$officer = docs_is_officer();
$admin = is_sysadmin();
$mode = docs_mode();
$now = date('Y-m-d H:i:s');
const DOC_MAX_BYTES = 3 * 1024 * 1024;

function doc_tpl(PDO $pdo, int $id, bool $content = false): ?array {
    $q = $pdo->prepare("SELECT id, title, description, filename, fields, active, created_at, updated_at"
        . ($content ? ', content' : '') . " FROM doc_templates WHERE id = ?");
    $q->execute([$id]);
    $t = $q->fetch();
    if (!$t) return null;
    if ($content && is_resource($t['content'])) $t['content'] = stream_get_contents($t['content']);
    $t['fields'] = json_decode((string)$t['fields'], true) ?: [];
    return $t;
}

function doc_req(PDO $pdo, int $id): ?array {
    $q = $pdo->prepare("SELECT * FROM doc_requests WHERE id = ?");
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** Поля, которые спрашиваем у заявителя (без автоматических) */
function doc_ask_fields(array $tpl): array {
    return array_values(array_filter($tpl['fields'], fn($f) => !in_array($f, DOC_AUTO_FIELDS, true)));
}

/** Собрать значения полей из формы */
function doc_read_form(array $fields, ?string &$err): array {
    $in = $_POST['f'] ?? [];
    $data = [];
    $err = null;
    foreach ($fields as $i => $f) {
        $v = trim(str_replace("\r", '', (string)($in[$i] ?? '')));
        if (mb_strlen($v) > 2000) $v = mb_substr($v, 0, 2000);
        if ($v === '' && !$err) $err = 'Заполните поле «' . $f . '»';
        $data[$f] = $v;
    }
    return $data;
}

function doc_upload_file(?string &$err): ?array {
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { $err = 'Выберите файл .docx'; return null; }
    if ($f['error'] !== UPLOAD_ERR_OK) { $err = 'Файл не загрузился (код ошибки ' . (int)$f['error'] . ')'; return null; }
    if ($f['size'] > DOC_MAX_BYTES) { $err = 'Файл больше 3 МБ'; return null; }
    if (!preg_match('/\.docx$/i', (string)$f['name'])) { $err = 'Нужен файл Word в формате .docx (не .doc и не .pdf)'; return null; }
    $bin = (string)file_get_contents($f['tmp_name']);
    $fields = docx_fields($bin);
    if ($fields === null) { $err = 'Файл не читается как документ Word .docx'; return null; }
    return ['bin' => $bin, 'fields' => $fields, 'name' => basename((string)$f['name'])];
}

function doc_save_blob(PDO $pdo, string $sql, array $params, string $bin): void {
    $st = $pdo->prepare($sql);
    $i = 1;
    foreach ($params as $p) {
        if ($p === '__BLOB__') $st->bindValue($i++, $bin, PDO::PARAM_LOB);
        else $st->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
}

function doc_filename(string $s): string {
    $s = trim(preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', ' ', $s));
    return $s !== '' ? $s : 'документ';
}

function doc_send_file(string $name, string $bin): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="document.docx"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: private, no-cache');
    echo $bin;
    exit;
}

/* ======================== Действия ======================== */
if (is_post()) {
    $a = $_POST['action'] ?? '';

    // ---- заявка на документ ----
    if ($a === 'order') {
        $tpl = doc_tpl($pdo, (int)($_POST['tpl'] ?? 0));
        if (!$tpl || !(int)$tpl['active']) { flash('Шаблон не найден', 'err'); redirect('docs.php'); }
        $data = doc_read_form(doc_ask_fields($tpl), $err);
        if ($err) {
            $_SESSION['doc_draft'] = [$tpl['id'], $data];
            flash($err, 'err');
            redirect('docs.php?t=' . $tpl['id']);
        }
        $me = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $me->execute([uid()]);
        $pdo->prepare("INSERT INTO doc_requests (template_id, template_title, user_id, user_name, data, status, created_at) VALUES (?, ?, ?, ?, ?, 'new', ?)")
            ->execute([$tpl['id'], $tpl['title'], uid(), (string)$me->fetchColumn(), json_encode($data, JSON_UNESCAPED_UNICODE), $now]);
        $rid = (int)$pdo->lastInsertId();
        foreach (doc_officer_ids($pdo) as $oid) {
            if ($oid === uid()) continue;
            notify($pdo, $oid, 'Новая заявка на документ «' . $tpl['title'] . '» от ' . ($_SESSION['full_name'] ?? 'пользователя'), 'docs.php?r=' . $rid, 'doc', $rid);
        }
        unset($_SESSION['doc_draft']);
        flash('Заявка отправлена. Ответственный за документооборот подготовит документ — статус виден здесь.');
        redirect('docs.php?r=' . $rid);
    }

    // ---- отмена своей заявки ----
    if ($a === 'cancel') {
        $r = doc_req($pdo, (int)($_POST['r'] ?? 0));
        if ($r && (int)$r['user_id'] === uid() && $r['status'] === 'new') {
            $pdo->prepare("UPDATE doc_requests SET status = 'cancelled', handled_at = ? WHERE id = ?")->execute([$now, $r['id']]);
            flash('Заявка отменена');
        }
        redirect('docs.php?tab=mine');
    }

    if (!$officer) { flash('Нет доступа', 'err'); redirect('docs.php'); }

    // ---- ответственный: исправить данные заявки ----
    if ($a === 'save_data') {
        $r = doc_req($pdo, (int)($_POST['r'] ?? 0));
        $tpl = $r ? doc_tpl($pdo, (int)$r['template_id']) : null;
        if ($r && $tpl) {
            $old = json_decode((string)$r['data'], true) ?: [];
            $data = doc_read_form(doc_ask_fields($tpl), $err) + $old;
            $pdo->prepare("UPDATE doc_requests SET data = ? WHERE id = ?")->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $r['id']]);
            flash('Данные заявки сохранены');
            if (($_POST['then'] ?? '') === 'download') redirect('docs.php?download=' . $r['id']);
        }
        redirect('docs.php?r=' . (int)($_POST['r'] ?? 0));
    }

    // ---- ответственный: статус ----
    if ($a === 'status') {
        $r = doc_req($pdo, (int)($_POST['r'] ?? 0));
        $st = $_POST['status'] ?? '';
        $comment = trim(mb_substr((string)($_POST['comment'] ?? ''), 0, 1000));
        if ($r && in_array($st, ['new', 'done', 'rejected'], true)) {
            if ($st === 'rejected' && $comment === '') {
                flash('Напишите причину — заявитель увидит её', 'err');
                redirect('docs.php?r=' . $r['id']);
            }
            $pdo->prepare("UPDATE doc_requests SET status = ?, comment = ?, handled_by = ?, handled_at = ? WHERE id = ?")
                ->execute([$st, $st === 'rejected' ? $comment : ($comment ?: $r['comment']), uid(), $now, $r['id']]);
            if ($r['user_id'] && (int)$r['user_id'] !== uid() && $st !== 'new') {
                $msg = $st === 'done'
                    ? 'Документ «' . $r['template_title'] . '» подготовлен и передан на подпись'
                    : 'Заявка на документ «' . $r['template_title'] . '» отклонена: ' . $comment;
                notify($pdo, (int)$r['user_id'], $msg, 'docs.php?r=' . $r['id'], 'doc', (int)$r['id']);
            }
            flash(['new' => 'Заявка снова в новых', 'done' => 'Отмечено: передана на подпись. Заявитель получил уведомление', 'rejected' => 'Заявка отклонена, заявитель получил уведомление'][$st]);
        }
        redirect('docs.php?r=' . (int)($_POST['r'] ?? 0));
    }

    // ---- шаблоны ----
    if ($a === 'tpl_add' || $a === 'tpl_replace') {
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 255));
        $desc = trim(mb_substr((string)($_POST['description'] ?? ''), 0, 1000));
        $file = doc_upload_file($err);
        if ($err) { flash($err, 'err'); redirect('docs.php?tab=templates'); }
        $fieldsJson = json_encode($file['fields'], JSON_UNESCAPED_UNICODE);
        if ($a === 'tpl_add') {
            if ($title === '') $title = preg_replace('/\.docx$/i', '', $file['name']);
            doc_save_blob($pdo, "INSERT INTO doc_templates (title, description, filename, content, fields, active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)",
                [$title, $desc, $file['name'], '__BLOB__', $fieldsJson, uid(), $now, $now], $file['bin']);
            $tid = (int)$pdo->lastInsertId();
        } else {
            $tid = (int)($_POST['tpl'] ?? 0);
            doc_save_blob($pdo, "UPDATE doc_templates SET filename = ?, content = ?, fields = ?, updated_at = ? WHERE id = ?",
                [$file['name'], '__BLOB__', $fieldsJson, $now, $tid], $file['bin']);
        }
        $ask = array_diff($file['fields'], DOC_AUTO_FIELDS);
        flash($file['fields']
            ? 'Шаблон загружен. Полей для заявителя: ' . count($ask) . ($ask ? ' (' . implode(', ', array_slice($ask, 0, 6)) . (count($ask) > 6 ? '…' : '') . ')' : '')
            : 'Шаблон загружен, но полей {…} в нём не найдено — документ будет выдаваться без изменений', $file['fields'] ? 'ok' : 'warn');
        redirect('docs.php?tab=templates#tpl' . $tid);
    }
    if ($a === 'tpl_save') {
        $tid = (int)($_POST['tpl'] ?? 0);
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 255));
        if ($title === '') { flash('Укажите название', 'err'); redirect('docs.php?tab=templates#tpl' . $tid); }
        $pdo->prepare("UPDATE doc_templates SET title = ?, description = ?, active = ?, updated_at = ? WHERE id = ?")
            ->execute([$title, trim(mb_substr((string)($_POST['description'] ?? ''), 0, 1000)), empty($_POST['active']) ? 0 : 1, $now, $tid]);
        flash('Шаблон сохранён');
        redirect('docs.php?tab=templates#tpl' . $tid);
    }
    if ($a === 'tpl_delete') {
        $tid = (int)($_POST['tpl'] ?? 0);
        $c = $pdo->prepare("SELECT COUNT(*) FROM doc_requests WHERE template_id = ?");
        $c->execute([$tid]);
        if ((int)$c->fetchColumn()) {
            $pdo->prepare("UPDATE doc_templates SET active = 0 WHERE id = ?")->execute([$tid]);
            flash('По шаблону уже есть заявки — он скрыт из списка «Заказать», а заявки сохранены', 'warn');
        } else {
            $pdo->prepare("DELETE FROM doc_templates WHERE id = ?")->execute([$tid]);
            flash('Шаблон удалён');
        }
        redirect('docs.php?tab=templates');
    }

    // ---- настройки раздела (администратор) ----
    if ($a === 'settings' && $admin) {
        $m = $_POST['mode'] ?? '';
        if (isset(DOCS_MODES[$m])) { setting_set('docs_mode', $m); flash('Режим раздела: ' . mb_strtolower(DOCS_MODES[$m])); }
        redirect('docs.php?tab=settings');
    }
    redirect('docs.php');
}

/* ======================== Файлы ======================== */
if (isset($_GET['download']) && $officer) {
    $r = doc_req($pdo, (int)$_GET['download']);
    $tpl = $r ? doc_tpl($pdo, (int)$r['template_id'], true) : null;
    if (!$r || !$tpl) { flash('Заявка или шаблон не найдены', 'err'); redirect('docs.php?tab=inbox'); }
    $bin = docx_fill($tpl['content'], doc_values($r, $tpl['fields']),
        $mode === 'test' ? 'ТЕСТОВЫЙ РЕЖИМ — документ сформирован для проверки, не является официальным' : null);
    if ($bin === null) { flash('Не удалось заполнить шаблон — проверьте файл', 'err'); redirect('docs.php?r=' . $r['id']); }
    $data = json_decode((string)$r['data'], true) ?: [];
    $who = (string)(reset($data) ?: $r['user_name']);
    doc_send_file(doc_filename($r['template_title'] . ' №' . $r['id'] . ' ' . mb_substr($who, 0, 40)) . '.docx', $bin);
}
if (isset($_GET['tpl_file']) && $officer) {
    $tpl = doc_tpl($pdo, (int)$_GET['tpl_file'], true);
    if (!$tpl) redirect('docs.php?tab=templates');
    doc_send_file(doc_filename($tpl['filename'] ?: $tpl['title'] . '.docx'), $tpl['content']);
}

/* ======================== Страницы ======================== */
$tabs = ['order' => 'Заказать', 'mine' => 'Мои заявки'];
if ($officer) $tabs = ['inbox' => 'Заявки', 'order' => 'Заказать', 'mine' => 'Мои заявки', 'templates' => 'Шаблоны'];
if ($admin) $tabs['settings'] = 'Настройки';
$newCnt = $officer ? (int)$pdo->query("SELECT COUNT(*) FROM doc_requests WHERE status = 'new'")->fetchColumn() : 0;
$tab = $_GET['tab'] ?? '';
if (!isset($tabs[$tab])) $tab = $officer ? 'inbox' : 'order';

function docs_head(array $tabs, string $on, int $newCnt, string $mode): void {
    ?>
    <section class="page-head">
        <div>
            <h1>Документы</h1>
            <div class="muted">Закажите документ по шаблону — ответственный за документооборот подготовит его и отнесёт на подпись.</div>
        </div>
    </section>
    <?php if ($mode === 'test'): ?>
        <div class="alert warn doc-test"><b>Тестовый режим.</b> Раздел видят только администраторы, ответственные за документооборот и тестировщики. Скачанные документы помечены «ТЕСТОВЫЙ РЕЖИМ».</div>
    <?php elseif ($mode === 'off'): ?>
        <div class="alert err doc-test"><b>Раздел выключен.</b> Его видят только администраторы. Включить — во вкладке «Настройки».</div>
    <?php endif; ?>
    <nav class="seg-tabs doc-tabs">
        <?php foreach ($tabs as $k => $label): ?>
            <a class="<?= $k === $on ? 'is-on' : '' ?>" href="docs.php?tab=<?= $k ?>"><?= e($label) ?><?php if ($k === 'inbox' && $newCnt): ?> <em class="count warn"><?= $newCnt ?></em><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function doc_field_input(string $name, int $i, string $value, bool $readonly = false): string {
    $type = doc_field_type($name);
    $ro = $readonly ? ' readonly' : '';
    $v = e($value);
    $inp = $type === 'textarea'
        ? "<textarea name=\"f[$i]\" rows=\"3\" required$ro>$v</textarea>"
        : "<input type=\"$type\" name=\"f[$i]\" value=\"$v\" required$ro" . ($type === 'tel' ? ' inputmode="tel"' : '') . '>';
    return '<label>' . e($name) . ' ' . $inp . '</label>';
}

/* ---------- форма заказа ---------- */
if (isset($_GET['t'])) {
    $tpl = doc_tpl($pdo, (int)$_GET['t']);
    if (!$tpl || (!(int)$tpl['active'] && !$officer)) { flash('Шаблон не найден', 'err'); redirect('docs.php'); }
    $ask = doc_ask_fields($tpl);
    $draft = ($_SESSION['doc_draft'][0] ?? 0) === (int)$tpl['id'] ? $_SESSION['doc_draft'][1] : [];
    unset($_SESSION['doc_draft']);
    page_start('Заказать: ' . $tpl['title'], ['section' => 'docs', 'width' => 'narrow', 'back' => 'docs.php?tab=order']);
    ?>
    <div class="card">
        <h1 class="mt0"><?= e($tpl['title']) ?></h1>
        <?php if ($tpl['description'] !== ''): ?><p class="muted"><?= nl2br(e($tpl['description'])) ?></p><?php endif; ?>
        <?php if ($mode === 'test'): ?><div class="alert warn">Тестовый режим: заявка учебная, документ будет с пометкой «ТЕСТОВЫЙ РЕЖИМ».</div><?php endif; ?>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="order">
            <input type="hidden" name="tpl" value="<?= (int)$tpl['id'] ?>">
            <?php foreach ($ask as $i => $f): ?>
                <?= doc_field_input($f, $i, (string)($draft[$f] ?? '')) ?>
            <?php endforeach; ?>
            <?php if (!$ask): ?><p class="muted">Заполнять ничего не нужно — документ готовится по вашим данным из профиля.</p><?php endif; ?>
            <p class="muted small mt0">Ваши ФИО и дата заявки подставятся автоматически. Проверьте написание — данные попадут в документ без изменений.</p>
            <div><button class="btn primary"><?= icon('send') ?> Отправить заявку</button></div>
        </form>
    </div>
    <?php
    page_end();
    exit;
}

/* ---------- одна заявка ---------- */
if (isset($_GET['r'])) {
    $r = doc_req($pdo, (int)$_GET['r']);
    if (!$r || (!$officer && (int)$r['user_id'] !== uid())) { flash('Заявка не найдена', 'err'); redirect('docs.php?tab=mine'); }
    $tpl = doc_tpl($pdo, (int)$r['template_id']);
    $data = json_decode((string)$r['data'], true) ?: [];
    $ask = $tpl ? doc_ask_fields($tpl) : array_keys($data);
    $handler = null;
    if ($r['handled_by']) {
        $hq = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $hq->execute([$r['handled_by']]);
        $handler = $hq->fetchColumn() ?: null;
    }
    $mineView = !$officer || (int)$r['user_id'] === uid() && ($_GET['as'] ?? '') === 'me';
    page_start('Заявка №' . $r['id'], ['section' => 'docs', 'width' => 'narrow', 'back' => $officer ? 'docs.php?tab=inbox' : 'docs.php?tab=mine']);
    ?>
    <div class="card doc-req">
        <div class="doc-req-head">
            <span class="doc-ico"><?= icon('file') ?></span>
            <div>
                <h1 class="mt0"><?= e($r['template_title']) ?></h1>
                <div class="muted small">Заявка №<?= (int)$r['id'] ?> · <?= e(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?> · <?= e($r['user_name'] ?: 'пользователь удалён') ?></div>
            </div>
            <?= doc_status_badge($r['status']) ?>
        </div>
        <?php if ($r['status'] === 'rejected' && $r['comment'] !== ''): ?>
            <div class="alert err">Причина отказа: <?= e($r['comment']) ?></div>
        <?php elseif ($r['comment'] !== ''): ?>
            <div class="alert info">Комментарий: <?= e($r['comment']) ?></div>
        <?php endif; ?>
        <?php if ($r['status'] === 'done'): ?>
            <p class="muted small">Документ подготовлен<?= $handler ? ' (' . e($handler) . ')' : '' ?> и передан на подпись <?= e(date('d.m.Y', strtotime((string)$r['handled_at']))) ?>.</p>
        <?php endif; ?>

        <?php if ($officer && !$mineView): ?>
            <?php if (!$tpl): ?><div class="alert err">Шаблон этой заявки удалён — скачать документ нельзя.</div><?php endif; ?>
            <form method="post" class="stack">
                <input type="hidden" name="action" value="save_data">
                <input type="hidden" name="r" value="<?= (int)$r['id'] ?>">
                <?php foreach ($ask as $i => $f): ?>
                    <?= doc_field_input($f, $i, (string)($data[$f] ?? '')) ?>
                <?php endforeach; ?>
                <p class="muted small mt0">Автоматически: заявитель — <?= e($r['user_name']) ?>, дата заявки — <?= e(date('d.m.Y', strtotime((string)$r['created_at']))) ?>. Если в данных опечатка, исправьте её здесь перед скачиванием.</p>
                <?php if ($tpl): ?>
                    <div class="btn-row">
                        <button class="btn primary" name="then" value="download"><?= icon('download') ?> Скачать документ .docx</button>
                        <button class="btn"><?= icon('save') ?> Сохранить исправления</button>
                    </div>
                <?php endif; ?>
            </form>
            <hr class="doc-hr">
            <div class="doc-status-actions">
                <?php if ($r['status'] !== 'done'): ?>
                    <form method="post"><input type="hidden" name="action" value="status"><input type="hidden" name="r" value="<?= (int)$r['id'] ?>">
                        <button class="btn" name="status" value="done"><?= icon('check') ?> Распечатан и передан на подпись</button></form>
                <?php endif; ?>
                <?php if ($r['status'] !== 'new'): ?>
                    <form method="post"><input type="hidden" name="action" value="status"><input type="hidden" name="r" value="<?= (int)$r['id'] ?>">
                        <button class="btn ghost" name="status" value="new"><?= icon('swap') ?> Вернуть в новые</button></form>
                <?php endif; ?>
                <?php if ($r['status'] !== 'rejected'): ?>
                    <details class="doc-reject">
                        <summary class="btn danger-outline"><?= icon('x') ?> Отклонить</summary>
                        <form method="post" class="stack">
                            <input type="hidden" name="action" value="status"><input type="hidden" name="r" value="<?= (int)$r['id'] ?>">
                            <label>Причина (увидит заявитель) <textarea name="comment" rows="2" required></textarea></label>
                            <div><button class="btn danger" name="status" value="rejected">Отклонить заявку</button></div>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <dl class="dl doc-dl">
                <?php foreach ($data as $k => $v): ?>
                    <dt><?= e($k) ?></dt><dd><?= nl2br(e(doc_field_out($k, (string)$v))) ?></dd>
                <?php endforeach; ?>
            </dl>
            <?php if ($r['status'] === 'new'): ?>
                <p class="muted small">Заявка ждёт ответственного за документооборот. Когда документ передадут на подпись, придёт уведомление.</p>
                <form method="post" data-confirm="Отменить заявку?">
                    <input type="hidden" name="action" value="cancel"><input type="hidden" name="r" value="<?= (int)$r['id'] ?>">
                    <button class="btn danger-outline small"><?= icon('x') ?> Отменить заявку</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    page_end();
    exit;
}

/* ---------- вкладки ---------- */
page_start('Документы', ['section' => 'docs']);
docs_head($tabs, $tab, $newCnt, $mode);

function doc_rows(array $rows, bool $showUser): void {
    if (!$rows) { echo '<div class="empty">Заявок нет.</div>'; return; }
    echo '<div class="doc-list card">';
    foreach ($rows as $r) {
        $data = json_decode((string)$r['data'], true) ?: [];
        $first = (string)(reset($data) ?: '');
        echo '<a class="doc-row" href="docs.php?r=' . (int)$r['id'] . '">'
           . '<span class="doc-ico">' . icon('file') . '</span>'
           . '<span class="doc-row-main"><b>' . e($r['template_title']) . '</b>'
           . '<span class="muted small">№' . (int)$r['id'] . ' · ' . e(date('d.m.Y H:i', strtotime((string)$r['created_at'])))
           . ($showUser ? ' · ' . e($r['user_name']) : '') . ($first !== '' ? ' · ' . e(mb_strimwidth($first, 0, 50, '…')) : '') . '</span></span>'
           . doc_status_badge($r['status']) . '</a>';
    }
    echo '</div>';
}

if ($tab === 'order') {
    $tpls = $pdo->query("SELECT id, title, description, fields FROM doc_templates WHERE active = 1 ORDER BY title")->fetchAll();
    if (!$tpls): ?>
        <div class="empty">Шаблонов пока нет.<?= $officer ? ' Загрузите первый во вкладке «Шаблоны».' : ' Их загружает ответственный за документооборот.' ?></div>
    <?php else: ?>
        <div class="tiles">
            <?php foreach ($tpls as $t): $n = count(array_diff(json_decode((string)$t['fields'], true) ?: [], DOC_AUTO_FIELDS)); ?>
                <a class="tile doc-tile" href="docs.php?t=<?= (int)$t['id'] ?>">
                    <div class="tile-top"><span class="doc-ico"><?= icon('file') ?></span><b class="tile-title"><?= e($t['title']) ?></b></div>
                    <?php if ($t['description'] !== ''): ?><p class="muted small doc-note"><?= e(mb_strimwidth($t['description'], 0, 160, '…')) ?></p><?php endif; ?>
                    <div class="tile-meta"><span class="muted small"><?= $n ? $n . ' ' . plural_word($n, 'поле', 'поля', 'полей') . ' заполнить' : 'заполнять нечего' ?></span><span class="btn small primary">Заказать</span></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif;
}

if ($tab === 'mine') {
    $q = $pdo->prepare("SELECT * FROM doc_requests WHERE user_id = ? ORDER BY id DESC LIMIT 200");
    $q->execute([uid()]);
    doc_rows($q->fetchAll(), false);
}

if ($tab === 'inbox') {
    $f = $_GET['st'] ?? 'new';
    if (!in_array($f, ['new', 'done', 'rejected', 'all'], true)) $f = 'new';
    ?>
    <nav class="doc-filter">
        <?php foreach (['new' => 'Новые', 'done' => 'Переданы на подпись', 'rejected' => 'Отклонённые', 'all' => 'Все'] as $k => $l): ?>
            <a class="<?= $k === $f ? 'is-on' : '' ?>" href="docs.php?tab=inbox&st=<?= $k ?>"><?= e($l) ?><?= $k === 'new' && $newCnt ? ' · ' . $newCnt : '' ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
    $rows = $f === 'all'
        ? $pdo->query("SELECT * FROM doc_requests ORDER BY id DESC LIMIT 300")->fetchAll()
        : (function () use ($pdo, $f) { $q = $pdo->prepare("SELECT * FROM doc_requests WHERE status = ? ORDER BY id " . ($f === 'new' ? 'ASC' : 'DESC') . " LIMIT 300"); $q->execute([$f]); return $q->fetchAll(); })();
    doc_rows($rows, true);
}

if ($tab === 'templates') {
    $tpls = $pdo->query("SELECT t.id, t.title, t.description, t.filename, t.fields, t.active, t.updated_at,
                                (SELECT COUNT(*) FROM doc_requests r WHERE r.template_id = t.id) AS req_cnt
                         FROM doc_templates t ORDER BY t.active DESC, t.title")->fetchAll();
    ?>
    <div class="card doc-help">
        <h2 class="mt0">Как сделать шаблон</h2>
        <ol>
            <li>Откройте служебную записку в Word и на месте меняющихся данных напишите поля в фигурных скобках: <code>{ФИО кандидата}</code>, <code>{Дата прохода}</code>, <code>{Причина прохода}</code>.</li>
            <li>Название поля — это подпись в форме заявки. Если в названии есть «дата», заявителю покажется календарь; «время» — выбор времени; «телефон» — номер; «причина», «цель», «комментарий» — поле для текста в несколько строк.</li>
            <li>Эти поля сайт заполнит сам: <?= implode(', ', array_map(fn($f) => '<code>{' . e($f) . '}</code>', DOC_AUTO_FIELDS)) ?>.</li>
            <li>Сохраните как .docx и загрузите ниже. Оформление документа сохраняется как есть.</li>
        </ol>
    </div>

    <div class="card">
        <h2 class="mt0">Загрузить шаблон</h2>
        <form method="post" enctype="multipart/form-data" class="stack">
            <input type="hidden" name="action" value="tpl_add">
            <div class="grid-2">
                <label>Название <input name="title" maxlength="255" placeholder="Служебная записка на разовый проход"></label>
                <label>Файл Word (.docx, до 3 МБ) <input type="file" name="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required></label>
            </div>
            <label>Пояснение для заявителя <textarea name="description" rows="2" maxlength="1000" placeholder="Когда нужна, сколько дней готовится, куда приходить за подписью"></textarea></label>
            <div><button class="btn primary"><?= icon('plus') ?> Загрузить</button></div>
        </form>
    </div>

    <?php if (!$tpls): ?>
        <div class="empty">Шаблонов пока нет.</div>
    <?php endif; ?>
    <?php foreach ($tpls as $t): $fields = json_decode((string)$t['fields'], true) ?: []; ?>
        <div class="card doc-tpl <?= (int)$t['active'] ? '' : 'is-off' ?>" id="tpl<?= (int)$t['id'] ?>">
            <div class="doc-req-head">
                <span class="doc-ico"><?= icon('file') ?></span>
                <div>
                    <h2 class="mt0"><?= e($t['title']) ?></h2>
                    <div class="muted small"><?= e($t['filename']) ?> · обновлён <?= e(date('d.m.Y', strtotime((string)$t['updated_at']))) ?> · заявок: <?= (int)$t['req_cnt'] ?></div>
                </div>
                <?= (int)$t['active'] ? '<span class="badge ok">в списке</span>' : '<span class="badge">скрыт</span>' ?>
            </div>
            <div class="doc-fields">
                <?php if (!$fields): ?><span class="muted small">Полей {…} не найдено</span><?php endif; ?>
                <?php foreach ($fields as $f): $auto = in_array($f, DOC_AUTO_FIELDS, true); ?>
                    <span class="badge <?= $auto ? 'info' : '' ?>" title="<?= $auto ? 'заполняется автоматически' : 'спросим у заявителя: ' . doc_field_type($f) ?>">{<?= e($f) ?>}<?= $auto ? ' · авто' : '' ?></span>
                <?php endforeach; ?>
            </div>
            <details class="doc-edit">
                <summary class="btn small ghost"><?= icon('edit') ?> Изменить</summary>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="tpl_save"><input type="hidden" name="tpl" value="<?= (int)$t['id'] ?>">
                    <label>Название <input name="title" value="<?= e($t['title']) ?>" maxlength="255" required></label>
                    <label>Пояснение для заявителя <textarea name="description" rows="2" maxlength="1000"><?= e($t['description']) ?></textarea></label>
                    <label class="switch-row"><input type="checkbox" name="active" value="1" <?= (int)$t['active'] ? 'checked' : '' ?>> <span>Показывать в списке «Заказать»</span></label>
                    <div><button class="btn primary small"><?= icon('save') ?> Сохранить</button></div>
                </form>
                <form method="post" enctype="multipart/form-data" class="stack">
                    <input type="hidden" name="action" value="tpl_replace"><input type="hidden" name="tpl" value="<?= (int)$t['id'] ?>">
                    <label>Заменить файл шаблона <input type="file" name="file" accept=".docx" required></label>
                    <div><button class="btn small"><?= icon('up') ?> Загрузить новую версию</button></div>
                </form>
                <div class="btn-row">
                    <a class="btn small ghost" href="docs.php?tpl_file=<?= (int)$t['id'] ?>"><?= icon('download') ?> Скачать шаблон</a>
                    <form method="post" data-confirm="Удалить шаблон «<?= e($t['title']) ?>»?">
                        <input type="hidden" name="action" value="tpl_delete"><input type="hidden" name="tpl" value="<?= (int)$t['id'] ?>">
                        <button class="btn small danger-outline"><?= icon('trash') ?> Удалить</button>
                    </form>
                </div>
            </details>
        </div>
    <?php endforeach;
}

if ($tab === 'settings' && $admin) {
    $officers = $pdo->query("SELECT id, full_name FROM users WHERE doc_officer = 1 ORDER BY full_name")->fetchAll();
    $testers = $pdo->query("SELECT id, full_name FROM users WHERE doc_tester = 1 ORDER BY full_name")->fetchAll();
    ?>
    <div class="card">
        <h2 class="mt0">Режим раздела</h2>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="settings">
            <fieldset class="choice">
                <?php foreach (['off' => 'Раздел видят только администраторы.', 'test' => 'Видят администраторы, ответственные и тестировщики. Документы помечены «ТЕСТОВЫЙ РЕЖИМ».', 'on' => 'Раздел видят все инструкторы и сотрудники управления (кроме аттестуемых).'] as $k => $d): ?>
                    <label class="choice-item"><input type="radio" name="mode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?>><span><b><?= e(DOCS_MODES[$k]) ?></b><small><?= e($d) ?></small></span></label>
                <?php endforeach; ?>
            </fieldset>
            <div><button class="btn primary"><?= icon('save') ?> Сохранить</button></div>
        </form>
    </div>
    <div class="card">
        <h2 class="mt0">Кто участвует</h2>
        <p class="muted small mt0">Отметить человека — в «Администрировании»: откройте карточку пользователя → вкладка «Роль» → «Документооборот».</p>
        <dl class="dl">
            <dt>Ответственные</dt>
            <dd><?= $officers ? implode(', ', array_map(fn($u) => e($u['full_name']), $officers)) : '<span class="muted">не назначены — заявки получают администраторы</span>' ?></dd>
            <dt>Тестировщики</dt>
            <dd><?= $testers ? implode(', ', array_map(fn($u) => e($u['full_name']), $testers)) : '<span class="muted">нет</span>' ?></dd>
        </dl>
        <a class="btn" href="admin.php#users"><?= icon('users') ?> Открыть пользователей</a>
    </div>
    <?php
}

page_end();
