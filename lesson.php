<?php
// Прохождение урока: подразделы по одному, пункты с отметками, прогресс сверху
require_once 'functions.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$q = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
$q->execute([$id]);
$lesson = $q->fetch();
$course = $lesson ? get_course($pdo, (int)$lesson['course_id']) : null;
if (!$lesson || !$course || !course_can_view($course)) {
    flash('Урок не найден', 'err');
    redirect('courses.php');
}
$cid = (int)$course['id'];

if (is_post()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'finish') {
        $ex = $pdo->prepare("SELECT 1 FROM lesson_done WHERE user_id = ? AND lesson_id = ?");
        $ex->execute([uid(), $id]);
        $now = date('Y-m-d H:i:s');
        if ($ex->fetchColumn()) {
            $pdo->prepare("UPDATE lesson_done SET completed_at = ? WHERE user_id = ? AND lesson_id = ?")->execute([$now, uid(), $id]);
        } else {
            $pdo->prepare("INSERT INTO lesson_done (user_id, lesson_id, completed_at) VALUES (?, ?, ?)")->execute([uid(), $id, $now]);
        }
        flash("Урок «{$lesson['title']}» завершён, прогресс сохранён");
        redirect("course.php?id=$cid");
    }
    if ($action === 'reset') {
        $pdo->prepare("DELETE FROM lesson_progress WHERE user_id = ? AND lesson_id = ?")->execute([uid(), $id]);
        $pdo->prepare("DELETE FROM lesson_done WHERE user_id = ? AND lesson_id = ?")->execute([uid(), $id]);
        flash('Прогресс урока сброшен', 'warn');
        redirect("lesson.php?id=$id");
    }
    redirect("lesson.php?id=$id");
}

$struct = lesson_structure($pdo, $id);
$dq = $pdo->prepare("SELECT item_id FROM lesson_progress WHERE user_id = ? AND lesson_id = ?");
$dq->execute([uid(), $id]);
$doneItems = array_flip(array_map('intval', $dq->fetchAll(PDO::FETCH_COLUMN)));
$st = $pdo->prepare("SELECT * FROM lesson_done WHERE user_id = ? AND lesson_id = ?");
$st->execute([uid(), $id]);
$state = $st->fetch() ?: ['last_section' => 0, 'completed_at' => null];

$total = 0; $done = 0; $firstOpen = null;
foreach ($struct as $i => &$sec) {
    $sec['done_cnt'] = 0;
    foreach ($sec['items'] as $it) {
        $total++;
        if (isset($doneItems[(int)$it['id']])) { $done++; $sec['done_cnt']++; }
    }
    $sec['complete'] = $sec['done_cnt'] === count($sec['items']);
    if ($firstOpen === null && !$sec['complete']) $firstOpen = $i;
}
unset($sec);
$start = min(max(0, (int)$state['last_section']), max(0, count($struct) - 1));
if ($firstOpen !== null && $struct[$start]['complete']) $start = $firstOpen;

$list = course_lessons($pdo, $cid);
$pos = array_search($id, array_map('intval', array_column($list, 'id')), true);
$next = $pos !== false && isset($list[$pos + 1]) ? $list[$pos + 1] : null;

page_start($lesson['title'], ['back' => "course.php?id=$cid", 'section' => 'courses']);
?>
<div class="lesson-progress" title="Пройдено пунктов: <?= $done ?> из <?= $total ?>">
    <span data-lp-bar style="width: <?= $total ? round($done / $total * 100) : 0 ?>%"></span>
</div>

<section class="page-head">
    <div>
        <div class="muted small"><?= e($course['title']) ?> · урок <?= $pos + 1 ?> из <?= count($list) ?></div>
        <h1><?= e($lesson['title']) ?></h1>
        <?php if (trim((string)$lesson['description']) !== ''): ?><p class="muted mt0"><?= nl2br(e($lesson['description'])) ?></p><?php endif; ?>
    </div>
    <div class="head-meta">
        <span class="badge <?= $state['completed_at'] ? 'ok' : 'info' ?>" data-lp-count><?= $state['completed_at'] ? 'Урок завершён' : "Пройдено $done из $total" ?></span>
        <?php if (course_can_edit($course)): ?>
            <a class="btn small" href="lesson_wizard.php?lesson=<?= $id ?>"><?= icon('edit') ?> Редактировать</a>
        <?php endif; ?>
    </div>
</section>

<?php if (!$struct): ?>
    <article class="card lesson-body">
        <?php if (trim((string)$lesson['content']) !== ''): ?>
            <?= nl2br(e($lesson['content'])) ?>
        <?php else: ?>
            <p class="muted">В уроке пока нет подразделов.</p>
        <?php endif; ?>
    </article>
    <form method="post" class="btn-row">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button name="action" value="finish" class="btn primary"><?= icon('check') ?> Завершить урок</button>
        <?php if ($next): ?><a class="btn" href="lesson.php?id=<?= (int)$next['id'] ?>">Следующий урок →</a><?php endif; ?>
    </form>
<?php else: ?>
<div class="lesson-player" data-lesson-player data-lesson="<?= $id ?>" data-start="<?= $start ?>" data-total="<?= $total ?>">
    <aside class="lp-nav">
        <ol>
            <?php foreach ($struct as $i => $sec): ?>
                <li><button type="button" class="<?= $sec['complete'] ? 'done' : '' ?>" data-goto="<?= $i ?>">
                    <span class="nav-num"><?= $sec['complete'] ? '✓' : $i + 1 ?></span>
                    <span class="nav-name"><?= e($sec['title']) ?></span>
                    <span class="nav-val" data-sec-count="<?= $i ?>"><?= $sec['done_cnt'] ?>/<?= count($sec['items']) ?></span>
                </button></li>
            <?php endforeach; ?>
        </ol>
        <form method="post" class="lp-actions">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button name="action" value="finish" class="btn primary"><?= icon('check') ?> Завершить урок</button>
            <button name="action" value="reset" class="btn" data-confirm="Сбросить прогресс урока? Все отметки снимутся."><?= icon('swap') ?> Сбросить прогресс</button>
        </form>
        <div class="lp-mode hide-sm">
            <div class="muted small">Переключать подразделы клавишами:</div>
            <label class="chk"><input type="radio" name="lp-mode" value="lr" checked> ← →</label>
            <label class="chk"><input type="radio" name="lp-mode" value="ud"> ↑ ↓</label>
            <div class="muted small lp-keys">Enter — отметить пункт, Backspace — снять отметку</div>
        </div>
    </aside>

    <div class="lp-main">
        <?php foreach ($struct as $i => $sec): ?>
            <section class="lp-section card" data-sec="<?= $i ?>" <?= $i === $start ? '' : 'hidden' ?>>
                <div class="step-count">Подраздел <?= $i + 1 ?> из <?= count($struct) ?></div>
                <h2 class="lp-title"><?= e($sec['title']) ?></h2>
                <?php if (!$sec['items']): ?>
                    <p class="muted">В подразделе нет пунктов.</p>
                <?php else: ?>
                    <ul class="lp-items">
                        <?php foreach ($sec['items'] as $it): $isDone = isset($doneItems[(int)$it['id']]);
                            $hasContent = trim((string)$it['content']) !== ''; ?>
                            <li class="lp-item <?= $isDone ? 'done' : '' ?>" data-item="<?= (int)$it['id'] ?>">
                                <button type="button" class="lp-check" aria-pressed="<?= $isDone ? 'true' : 'false' ?>" title="Отметить пункт"><?= icon('check') ?></button>
                                <div class="lp-item-body">
                                    <button type="button" class="lp-item-title <?= $hasContent ? 'has-content' : '' ?>"><?= e($it['title']) ?></button>
                                    <?php if ($hasContent): ?>
                                        <div class="lp-content" hidden><?= nl2br(e($it['content'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="lp-controls">
                    <button type="button" class="btn" data-lp-prev <?= $i === 0 ? 'disabled' : '' ?>>← Назад</button>
                    <?php if ($i < count($struct) - 1): ?>
                        <button type="button" class="btn primary" data-lp-next>Вперёд →</button>
                    <?php elseif ($next): ?>
                        <a class="btn primary" href="lesson.php?id=<?= (int)$next['id'] ?>">Следующий урок →</a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php page_end();
