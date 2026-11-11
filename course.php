<?php
// Просмотр курса: описание, уроки и мой прогресс
require_once 'functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$course = get_course($pdo, $id);
if (!$course || !course_can_view($course)) {
    flash('Курс не найден', 'err');
    redirect('courses.php');
}
if (is_post() && ($_POST['action'] ?? '') === 'copy') {
    $newId = copy_course($pdo, $id, uid());
    flash('Курс сохранён в «Мои курсы» — эта копия видна только вам, её можно менять как удобно');
    redirect("courses.php?pick=$newId#mine");
}
$lessons  = course_lessons($pdo, $id);
$progress = course_progress($pdo, $id, uid());
$doneCnt  = count(array_filter($progress, fn($p) => $p['completed']));

page_start($course['title'], ['back' => 'courses.php', 'section' => 'courses', 'width' => 'narrow']);
?>
<section class="page-head">
    <div>
        <h1><?= e($course['title']) ?></h1>
        <div class="head-meta">
            <?php if ($course['status'] !== 'published'): ?><span class="badge">черновик — виден только вам</span><?php endif; ?>
            <span class="muted small"><?= $course['author'] ? 'Автор: ' . e($course['author']) : '' ?></span>
        </div>
    </div>
    <div class="btn-row">
        <?php if ((int)$course['created_by'] !== uid()): ?>
            <form method="post" class="inline">
                <input type="hidden" name="action" value="copy">
                <button class="btn" title="Личная копия, которую можно менять под себя"><?= icon('copy') ?> Сохранить себе</button>
            </form>
        <?php endif; ?>
        <?php if (course_can_edit($course)): ?>
            <a class="btn" href="course_wizard.php?id=<?= $id ?>"><?= icon('edit') ?> Редактировать</a>
        <?php endif; ?>
    </div>
</section>

<?php if (trim((string)$course['description']) !== ''): ?>
    <div class="card course-desc"><?= nl2br(e($course['description'])) ?></div>
<?php endif; ?>

<section>
    <h2>Уроки <?php if ($lessons): ?><span class="muted small">пройдено <?= $doneCnt ?> из <?= count($lessons) ?></span><?php endif; ?></h2>
    <?php if (!$lessons): ?>
        <div class="empty">В курсе пока нет уроков.</div>
    <?php else: ?>
        <ol class="lesson-list">
            <?php foreach ($lessons as $i => $l):
                $p = $progress[(int)$l['id']] ?? ['done' => 0, 'total' => 0, 'completed' => false];
                $pctL = $p['total'] ? round($p['done'] / $p['total'] * 100) : 0; ?>
                <li>
                    <a class="lesson-item" href="lesson.php?id=<?= (int)$l['id'] ?>">
                        <span class="lesson-num <?= $p['completed'] ? 'done' : '' ?>"><?= $p['completed'] ? '✓' : $i + 1 ?></span>
                        <span class="lesson-title"><?= e($l['title']) ?>
                            <?php if (trim((string)$l['description']) !== ''): ?><span class="lesson-desc muted small"><?= e(mb_strimwidth((string)$l['description'], 0, 120, '…')) ?></span><?php endif; ?>
                        </span>
                        <span class="lesson-state">
                            <?php if ($p['completed']): ?><span class="badge ok">пройден</span>
                            <?php elseif ($p['done']): ?><span class="badge info"><?= $pctL ?>%</span>
                            <?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
<?php page_end();
