<?php
// Мастер курса: название → описание → уроки → предпросмотр.
// «Сохранить» оставляет курс видимым только автору, «Опубликовать» делает его общедоступным.
require_once 'functions.php';
require_login();

const CW_STEPS = [1 => 'Название', 2 => 'Описание', 3 => 'Уроки', 4 => 'Предпросмотр'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c  = $id ? get_course($pdo, $id) : null;
if ($id && (!$c || !course_can_edit($c))) {
    flash('Курс не найден или нет прав на редактирование', 'err');
    redirect('courses.php');
}
$step = max(1, min(4, (int)($_GET['step'] ?? $_POST['step'] ?? ($c['wizard_step'] ?? 1))));
if (!$c) $step = 1;
$published = $c && $c['status'] === 'published';

if (is_post()) {
    $nav = (string)($_POST['nav'] ?? (isset($_POST['goto']) ? 'goto' . (int)$_POST['goto'] : 'next'));
    $now = date('Y-m-d H:i:s');

    if ($nav === 'delete' && $c && (int)$c['created_by'] === uid()) {
        foreach (course_lessons($pdo, $id) as $l) delete_lesson($pdo, (int)$l['id']);
        $pdo->prepare("DELETE FROM courses WHERE id = ?")->execute([$id]);
        flash('Курс удалён', 'warn');
        redirect('courses.php');
    }

    // уроки: удалить / переставить
    if (in_array($_POST['action'] ?? '', ['delete_lesson', 'move_lesson'], true) && $c) {
        $lid = (int)($_POST['lesson'] ?? 0);
        $chk = $pdo->prepare("SELECT 1 FROM lessons WHERE id = ? AND course_id = ?");
        $chk->execute([$lid, $id]);
        if ($chk->fetchColumn()) {
            if ($_POST['action'] === 'delete_lesson') {
                delete_lesson($pdo, $lid);
                flash('Урок удалён', 'warn');
            } else {
                move_sorted($pdo, 'lessons', 'course_id', $id, $lid, ($_POST['dir'] ?? '') === 'up' ? -1 : 1);
            }
        }
        redirect("course_wizard.php?id=$id&step=3");
    }

    if ($step === 1) {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            flash('Укажите название курса', 'err');
            redirect($id ? "course_wizard.php?id=$id&step=1" : 'course_wizard.php');
        }
        if ($c) {
            $pdo->prepare("UPDATE courses SET title = ?, updated_by = ?, updated_at = ? WHERE id = ?")->execute([$title, uid(), $now, $id]);
        } else {
            $pdo->prepare("INSERT INTO courses (title, description, created_by, updated_by, updated_at, status) VALUES (?, '', ?, ?, ?, 'draft')")
                ->execute([$title, uid(), uid(), $now]);
            $id = (int)$pdo->lastInsertId();
        }
    }
    if ($step === 2 && $c) {
        $pdo->prepare("UPDATE courses SET description = ?, updated_by = ?, updated_at = ? WHERE id = ?")
            ->execute([trim($_POST['description'] ?? ''), uid(), $now, $id]);
    }

    if ($nav === 'save') {
        $pdo->prepare("UPDATE courses SET wizard_step = ? WHERE id = ?")->execute([$step, $id]);
        flash($published ? 'Изменения сохранены' : 'Курс сохранён — он виден только вам. Опубликуйте его, когда будет готов.');
        redirect("courses.php?pick=$id#mine");
    }
    if ($nav === 'publish') {
        $pdo->prepare("UPDATE courses SET status = 'published', wizard_step = 1, updated_at = ? WHERE id = ?")->execute([$now, $id]);
        flash('Курс опубликован — теперь он виден всем');
        redirect("course.php?id=$id");
    }
    $to = $step + 1;
    if ($nav === 'prev') $to = $step - 1;
    if (str_starts_with($nav, 'goto')) $to = (int)substr($nav, 4);
    $to = max(1, min(4, $to));
    $pdo->prepare("UPDATE courses SET wizard_step = ? WHERE id = ?")->execute([$to, $id]);
    redirect("course_wizard.php?id=$id&step=$to");
}

if ($c && (int)$c['wizard_step'] !== $step) {
    $pdo->prepare("UPDATE courses SET wizard_step = ? WHERE id = ?")->execute([$step, $id]);
}
$lessons = $c ? course_lessons($pdo, $id) : [];
$steps = [];
foreach (CW_STEPS as $n => $label) $steps[$n] = [$label, 'submit:cw'];

page_start($c ? 'Курс: ' . $c['title'] : 'Новый курс', ['back' => 'courses.php', 'width' => 'narrow', 'section' => 'courses']);
?>
<form method="post" id="cw" class="att-wizard">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="step" value="<?= $step ?>">
</form>
<?php steps_nav($steps, $step); ?>

<div class="card">
<?php if ($step === 1): ?>
    <h1><?= $c ? 'Название курса' : 'Новый курс' ?></h1>
    <label>Название
        <input form="cw" name="title" value="<?= e($c['title'] ?? '') ?>" placeholder="Например: Базовая первая помощь" required data-autofocus>
    </label>
    <p class="muted small">Пока курс не опубликован, он виден только вам — в блоке «Мои курсы».</p>

<?php elseif ($step === 2): ?>
    <h1>Описание</h1>
    <label>Для кого курс и чему он учит
        <textarea form="cw" name="description" rows="6" placeholder="Кратко о курсе"><?= e($c['description'] ?? '') ?></textarea>
    </label>

<?php elseif ($step === 3): ?>
    <h1>Уроки</h1>
    <p class="muted small">Урок состоит из подразделов, в подразделах — пункты. Мастер урока откроется по кнопке ниже.</p>
    <?php if (!$lessons): ?>
        <div class="empty small-empty">Уроков пока нет.</div>
    <?php else: ?>
        <ol class="lesson-list">
            <?php foreach ($lessons as $i => $l): $struct = lesson_structure($pdo, (int)$l['id']); ?>
                <li>
                    <a class="lesson-item" href="lesson_wizard.php?lesson=<?= (int)$l['id'] ?>&ret=cw">
                        <span class="lesson-num"><?= $i + 1 ?></span>
                        <span class="lesson-title"><?= e($l['title']) ?>
                            <span class="muted small"> · <?= plural(count($struct), 'подраздел', 'подраздела', 'подразделов') ?></span></span>
                    </a>
                    <form method="post" class="lesson-actions">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="step" value="3">
                        <input type="hidden" name="lesson" value="<?= (int)$l['id'] ?>">
                        <button name="action" value="move_lesson" class="icon-btn" title="Выше" onclick="this.form.dir.value='up'" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('up') ?></button>
                        <button name="action" value="move_lesson" class="icon-btn" title="Ниже" onclick="this.form.dir.value='down'" <?= $i === count($lessons) - 1 ? 'disabled' : '' ?>><?= icon('down') ?></button>
                        <input type="hidden" name="dir" value="">
                        <a class="icon-btn" href="lesson_wizard.php?lesson=<?= (int)$l['id'] ?>&ret=cw" title="Редактировать"><?= icon('edit') ?></a>
                        <button name="action" value="delete_lesson" class="icon-btn danger" title="Удалить" data-confirm="Удалить урок «<?= e($l['title']) ?>»?"><?= icon('trash') ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <p><a class="btn" href="lesson_wizard.php?course=<?= $id ?>&ret=cw"><?= icon('plus') ?> Добавить урок</a></p>

<?php else: ?>
    <h1>Предпросмотр</h1>
    <div class="preview-course">
        <h2 class="mt0"><?= e($c['title']) ?></h2>
        <?php if (trim((string)$c['description']) !== ''): ?><p><?= nl2br(e($c['description'])) ?></p><?php endif; ?>
        <?php if (!$lessons): ?>
            <p class="muted">В курсе пока нет уроков.</p>
        <?php endif; ?>
        <?php foreach ($lessons as $i => $l): $struct = lesson_structure($pdo, (int)$l['id']); ?>
            <details class="preview-lesson" <?= $i === 0 ? 'open' : '' ?>>
                <summary><span class="lesson-num"><?= $i + 1 ?></span> <b><?= e($l['title']) ?></b></summary>
                <div class="preview-body">
                    <?php if (trim((string)$l['description']) !== ''): ?><p class="muted"><?= nl2br(e($l['description'])) ?></p><?php endif; ?>
                    <?php foreach ($struct as $sec): ?>
                        <div class="preview-section">
                            <b><?= e($sec['title']) ?></b>
                            <ul><?php foreach ($sec['items'] as $it): ?><li><?= e($it['title']) ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endforeach; ?>
                    <div class="btn-row">
                        <a class="btn small" href="lesson.php?id=<?= (int)$l['id'] ?>&preview=1"><?= icon('eye') ?> Посмотреть урок</a>
                        <a class="btn small" href="lesson_wizard.php?lesson=<?= (int)$l['id'] ?>&ret=cw"><?= icon('edit') ?> Редактировать</a>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<div class="wizard-buttons wizard-bar">
    <div class="btn-row">
        <?php if ($c && (int)$c['created_by'] === uid() && !$published): ?>
            <button form="cw" name="nav" value="delete" class="btn danger" formnovalidate data-confirm="Удалить курс «<?= e($c['title']) ?>»?"><?= icon('trash') ?></button>
        <?php endif; ?>
        <button form="cw" name="nav" value="save" class="btn"><?= icon('save') ?> Сохранить</button>
        <?php if ($step > 1): ?>
            <button form="cw" name="nav" value="prev" class="btn" formnovalidate>← Назад</button>
        <?php endif; ?>
        <?php if ($step < 4): ?>
            <button form="cw" name="nav" value="next" class="btn primary">Далее →</button>
        <?php elseif (!$published): ?>
            <button form="cw" name="nav" value="publish" class="btn primary"><?= icon('send') ?> Опубликовать</button>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
