<?php
// Мастер урока: название → краткое описание → подразделы и пункты.
// Устроен как мастер станции: черновиков нет, по шагам можно переходить в любом порядке.
require_once 'functions.php';
require_login();

$lid = (int)($_GET['lesson'] ?? $_POST['lesson'] ?? 0);
$cid = (int)($_GET['course'] ?? $_POST['course'] ?? 0);
$ret = ($_GET['ret'] ?? $_POST['ret'] ?? '') === 'cw' ? 'cw' : 'course';
$lesson = null;
if ($lid) {
    $q = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
    $q->execute([$lid]);
    $lesson = $q->fetch() ?: null;
    if ($lesson) $cid = (int)$lesson['course_id'];
}
$course = get_course($pdo, $cid);
if (!$course || ($lid && !$lesson) || !course_can_edit($course)) {
    flash('Урок или курс не найден, либо нет прав на редактирование', 'err');
    redirect('courses.php');
}
$step = max(1, min(3, (int)($_GET['step'] ?? $_POST['step'] ?? 1)));
if (!$lesson) $step = 1;
$done = $ret === 'cw' ? "course_wizard.php?id=$cid&step=3" : "course.php?id=$cid";
$self = fn(int $s) => "lesson_wizard.php?lesson=$lid&ret=$ret&step=$s";

if (is_post()) {
    $now = date('Y-m-d H:i:s');
    $action = $_POST['action'] ?? '';

    /* ----- редактирование структуры (шаг 3) ----- */
    if ($lesson && $action !== '') {
        $sec  = (int)($_POST['section'] ?? 0);
        $item = (int)($_POST['item'] ?? 0);
        $dir  = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        $secOk = function (int $sid) use ($pdo, $lid): bool {
            $q = $pdo->prepare("SELECT 1 FROM lesson_sections WHERE id = ? AND lesson_id = ?");
            $q->execute([$sid, $lid]);
            return (bool)$q->fetchColumn();
        };
        $itemSec = function (int $iid) use ($pdo, $lid): int {
            $q = $pdo->prepare("SELECT i.section_id FROM lesson_items i JOIN lesson_sections s ON s.id = i.section_id WHERE i.id = ? AND s.lesson_id = ?");
            $q->execute([$iid, $lid]);
            return (int)$q->fetchColumn();
        };
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if ($action === 'add_section' && $title !== '') {
            $pdo->prepare("INSERT INTO lesson_sections (lesson_id, title, sort_order) VALUES (?, ?, ?)")
                ->execute([$lid, $title, next_sort($pdo, 'lesson_sections', 'lesson_id', $lid)]);
            $sec = (int)$pdo->lastInsertId();
        } elseif ($action === 'rename_section' && $secOk($sec) && $title !== '') {
            $pdo->prepare("UPDATE lesson_sections SET title = ? WHERE id = ?")->execute([$title, $sec]);
        } elseif ($action === 'delete_section' && $secOk($sec)) {
            $pdo->prepare("DELETE FROM lesson_progress WHERE item_id IN (SELECT id FROM lesson_items WHERE section_id = ?)")->execute([$sec]);
            $pdo->prepare("DELETE FROM lesson_items WHERE section_id = ?")->execute([$sec]);
            $pdo->prepare("DELETE FROM lesson_sections WHERE id = ?")->execute([$sec]);
            $sec = 0;
        } elseif ($action === 'move_section' && $secOk($sec)) {
            move_sorted($pdo, 'lesson_sections', 'lesson_id', $lid, $sec, $dir);
        } elseif ($action === 'add_item' && $secOk($sec) && $title !== '') {
            $pdo->prepare("INSERT INTO lesson_items (section_id, title, content, sort_order) VALUES (?, ?, ?, ?)")
                ->execute([$sec, $title, $content, next_sort($pdo, 'lesson_items', 'section_id', $sec)]);
        } elseif ($action === 'save_item' && ($s = $itemSec($item)) && $title !== '') {
            $pdo->prepare("UPDATE lesson_items SET title = ?, content = ? WHERE id = ?")->execute([$title, $content, $item]);
            $sec = $s;
        } elseif ($action === 'delete_item' && ($s = $itemSec($item))) {
            $pdo->prepare("DELETE FROM lesson_progress WHERE item_id = ?")->execute([$item]);
            $pdo->prepare("DELETE FROM lesson_items WHERE id = ?")->execute([$item]);
            $sec = $s;
        } elseif ($action === 'move_item' && ($s = $itemSec($item))) {
            move_sorted($pdo, 'lesson_items', 'section_id', $s, $item, $dir);
            $sec = $s;
        } elseif ($title === '' && in_array($action, ['add_section', 'rename_section', 'add_item', 'save_item'], true)) {
            flash('Укажите название', 'err');
        }
        $pdo->prepare("UPDATE lessons SET updated_by = ?, updated_at = ? WHERE id = ?")->execute([uid(), $now, $lid]);
        redirect($self(3) . ($sec ? "#sec-$sec" : ''));
    }

    /* ----- шаги 1 и 2 ----- */
    if ($step === 1) {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            flash('Укажите название урока', 'err');
            redirect($lid ? $self(1) : "lesson_wizard.php?course=$cid&ret=$ret");
        }
        if ($lesson) {
            $pdo->prepare("UPDATE lessons SET title = ?, updated_by = ?, updated_at = ? WHERE id = ?")->execute([$title, uid(), $now, $lid]);
        } else {
            $pdo->prepare("INSERT INTO lessons (course_id, title, description, sort_order, created_by, updated_by, updated_at) VALUES (?, ?, '', ?, ?, ?, ?)")
                ->execute([$cid, $title, next_sort($pdo, 'lessons', 'course_id', $cid), uid(), uid(), $now]);
            $lid = (int)$pdo->lastInsertId();
        }
    }
    if ($step === 2 && $lesson) {
        $pdo->prepare("UPDATE lessons SET description = ?, updated_by = ?, updated_at = ? WHERE id = ?")
            ->execute([trim($_POST['description'] ?? ''), uid(), $now, $lid]);
    }
    $pdo->prepare("UPDATE courses SET updated_by = ?, updated_at = ? WHERE id = ?")->execute([uid(), $now, $cid]);

    $nav = (string)($_POST['nav'] ?? (isset($_POST['goto']) ? 'goto' . (int)$_POST['goto'] : 'next'));
    if ($nav === 'finish') {
        flash('Урок сохранён');
        redirect($done);
    }
    $to = $step + 1;
    if ($nav === 'prev') $to = $step - 1;
    if (str_starts_with($nav, 'goto')) $to = (int)substr($nav, 4);
    redirect("lesson_wizard.php?lesson=$lid&ret=$ret&step=" . max(1, min(3, $to)));
}

$struct = $lesson ? lesson_structure($pdo, $lid) : [];
$steps = [];
foreach ([1 => 'Название', 2 => 'Описание', 3 => 'Подразделы'] as $n => $label) $steps[$n] = [$label, 'submit:lw'];

page_start($lesson ? 'Урок: ' . $lesson['title'] : 'Новый урок', ['back' => $done, 'width' => 'narrow', 'section' => 'courses']);
?>
<form method="post" id="lw">
    <input type="hidden" name="lesson" value="<?= $lid ?>">
    <input type="hidden" name="course" value="<?= $cid ?>">
    <input type="hidden" name="ret" value="<?= $ret ?>">
    <input type="hidden" name="step" value="<?= $step ?>">
</form>
<?php steps_nav($steps, $step); ?>

<div class="card">
    <div class="muted small"><?= e($course['title']) ?></div>
<?php if ($step === 1): ?>
    <h1><?= $lesson ? 'Название урока' : 'Новый урок' ?></h1>
    <label>Название
        <input form="lw" name="title" value="<?= e($lesson['title'] ?? '') ?>" placeholder="Например: Остановка кровотечения" required data-autofocus>
    </label>

<?php elseif ($step === 2): ?>
    <h1>Краткое описание</h1>
    <label>О чём урок
        <textarea form="lw" name="description" rows="5" placeholder="Пара предложений о содержании урока"><?= e($lesson['description'] ?? '') ?></textarea>
    </label>

<?php else: ?>
    <h1>Подразделы</h1>
    <p class="muted small">Подраздел показывается учащемуся отдельным блоком. Внутри — пункты: название и, при необходимости, краткое содержание, которое раскрывается по нажатию.</p>

    <?php foreach ($struct as $si => $sec): $sid = (int)$sec['id']; ?>
        <section class="lw-section" id="sec-<?= $sid ?>">
            <div class="lw-section-head">
                <span class="lesson-num"><?= $si + 1 ?></span>
                <form method="post" class="lw-rename">
                    <input type="hidden" name="lesson" value="<?= $lid ?>"><input type="hidden" name="ret" value="<?= $ret ?>">
                    <input type="hidden" name="section" value="<?= $sid ?>">
                    <input name="title" value="<?= e($sec['title']) ?>" aria-label="Название подраздела" required>
                    <button name="action" value="rename_section" class="btn small" title="Сохранить название подраздела"><?= icon('check') ?><span class="hide-sm"> Сохранить</span></button>
                </form>
                <form method="post" class="lw-tools">
                    <input type="hidden" name="lesson" value="<?= $lid ?>"><input type="hidden" name="ret" value="<?= $ret ?>">
                    <input type="hidden" name="section" value="<?= $sid ?>"><input type="hidden" name="dir" value="">
                    <button name="action" value="move_section" class="icon-btn boxed" title="Выше" onclick="this.form.dir.value='up'" <?= $si === 0 ? 'disabled' : '' ?>><?= icon('up') ?></button>
                    <button name="action" value="move_section" class="icon-btn boxed" title="Ниже" onclick="this.form.dir.value='down'" <?= $si === count($struct) - 1 ? 'disabled' : '' ?>><?= icon('down') ?></button>
                    <button name="action" value="delete_section" class="icon-btn boxed danger" title="Удалить подраздел" data-confirm="Удалить подраздел «<?= e($sec['title']) ?>» со всеми пунктами?"><?= icon('trash') ?></button>
                </form>
            </div>

            <!-- уже созданные пункты -->
            <div class="lw-block">
                <div class="lw-block-title">Пункты подраздела <span class="count"><?= count($sec['items']) ?></span></div>
                <?php if (!$sec['items']): ?>
                    <p class="muted small mt0">Пунктов пока нет — добавьте первый ниже.</p>
                <?php else: ?>
                    <ol class="lw-items">
                        <?php foreach ($sec['items'] as $ii => $it): ?>
                            <li>
                                <details class="lw-item">
                                    <summary>
                                        <span class="lw-item-num"><?= $ii + 1 ?></span>
                                        <span class="lw-item-title"><?= e($it['title']) ?>
                                            <?php if (trim((string)$it['content']) !== ''): ?><span class="muted small lw-item-preview"><?= e(mb_strimwidth((string)$it['content'], 0, 70, '…')) ?></span><?php endif; ?></span>
                                        <span class="btn small lw-edit-btn"><?= icon('edit') ?><span class="hide-sm"> Изменить</span></span>
                                    </summary>
                                    <form method="post" class="stack lw-item-form">
                                        <input type="hidden" name="lesson" value="<?= $lid ?>"><input type="hidden" name="ret" value="<?= $ret ?>">
                                        <input type="hidden" name="item" value="<?= (int)$it['id'] ?>"><input type="hidden" name="dir" value="">
                                        <label>Название пункта <input name="title" value="<?= e($it['title']) ?>" required></label>
                                        <label>Краткое содержание <span class="muted">(необязательно)</span>
                                            <textarea name="content" rows="3"><?= e($it['content']) ?></textarea></label>
                                        <div class="lw-form-actions">
                                            <button name="action" value="save_item" class="btn small primary"><?= icon('save') ?> Сохранить изменения</button>
                                            <span class="lw-form-tools">
                                                <button name="action" value="move_item" class="icon-btn boxed" title="Выше" onclick="this.form.dir.value='up'" formnovalidate <?= $ii === 0 ? 'disabled' : '' ?>><?= icon('up') ?></button>
                                                <button name="action" value="move_item" class="icon-btn boxed" title="Ниже" onclick="this.form.dir.value='down'" formnovalidate <?= $ii === count($sec['items']) - 1 ? 'disabled' : '' ?>><?= icon('down') ?></button>
                                                <button name="action" value="delete_item" class="icon-btn boxed danger" title="Удалить пункт" formnovalidate data-confirm="Удалить пункт «<?= e($it['title']) ?>»?"><?= icon('trash') ?></button>
                                            </span>
                                        </div>
                                    </form>
                                </details>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>

            <!-- создание нового пункта -->
            <details class="lw-new-item" <?= !$sec['items'] ? 'open' : '' ?>>
                <summary class="btn small btn-outline-info"><?= icon('plus') ?> Новый пункт</summary>
                <form method="post" class="stack lw-new-form">
                    <div class="lw-block-title">Новый пункт в подразделе «<?= e($sec['title']) ?>»</div>
                    <input type="hidden" name="lesson" value="<?= $lid ?>"><input type="hidden" name="ret" value="<?= $ret ?>">
                    <input type="hidden" name="section" value="<?= $sid ?>">
                    <label>Название пункта <input name="title" required placeholder="Например: Наложить давящую повязку"></label>
                    <label>Краткое содержание <span class="muted">(необязательно)</span>
                        <textarea name="content" rows="3" placeholder="Раскроется, когда учащийся нажмёт на название"></textarea></label>
                    <div class="lw-form-actions">
                        <button name="action" value="add_item" class="btn small btn-success"><?= icon('check') ?> Создать пункт</button>
                    </div>
                </form>
            </details>
        </section>
    <?php endforeach; ?>

    <form method="post" class="lw-new-section">
        <input type="hidden" name="lesson" value="<?= $lid ?>"><input type="hidden" name="ret" value="<?= $ret ?>">
        <div class="lw-block-title">Новый подраздел</div>
        <div class="inline-form">
            <input name="title" required placeholder="Например: Виды кровотечений" aria-label="Название нового подраздела">
            <button name="action" value="add_section" class="btn btn-dark"><?= icon('plus') ?> Создать подраздел</button>
        </div>
    </form>
<?php endif; ?>
</div>

<div class="wizard-buttons wizard-bar">
    <div class="btn-row">
        <?php if ($step > 1): ?>
            <button form="lw" name="nav" value="prev" class="btn" formnovalidate>← Назад</button>
        <?php else: ?>
            <a class="btn" href="<?= e($done) ?>">Отменить</a>
        <?php endif; ?>
        <?php if ($step < 3): ?>
            <button form="lw" name="nav" value="next" class="btn primary">Далее →</button>
        <?php else: ?>
            <button form="lw" name="nav" value="finish" class="btn primary"><?= icon('check') ?> Готово</button>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
