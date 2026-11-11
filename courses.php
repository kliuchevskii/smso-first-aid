<?php
// Курсы: общедоступные (опубликованные) и мои (черновики и опубликованные мной)
require_once 'functions.php';
require_login();

if (is_post()) {
    $id = (int)($_POST['course'] ?? 0);
    $c  = get_course($pdo, $id);
    $action = $_POST['action'] ?? '';
    if (!$c || !course_can_edit($c)) {
        flash('Курс не найден или нет прав', 'err');
        redirect('courses.php');
    }
    if ($action === 'publish') {
        $pdo->prepare("UPDATE courses SET status = 'published', updated_by = ?, updated_at = ? WHERE id = ?")->execute([uid(), date('Y-m-d H:i:s'), $id]);
        flash("Курс «{$c['title']}» опубликован — теперь он виден всем");
    } elseif ($action === 'unpublish' && (int)$c['created_by'] === uid()) {
        $pdo->prepare("UPDATE courses SET status = 'draft', updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);
        flash("Курс «{$c['title']}» снят с публикации — виден только вам", 'warn');
    } elseif ($action === 'delete' && (int)$c['created_by'] === uid()) {
        foreach (course_lessons($pdo, $id) as $l) delete_lesson($pdo, (int)$l['id']);
        $pdo->prepare("DELETE FROM courses WHERE id = ?")->execute([$id]);
        flash("Курс «{$c['title']}» удалён", 'warn');
        redirect('courses.php#mine');
    }
    redirect("courses.php?pick=$id#mine");
}

$lessonCnt = "(SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id)";
$public = $pdo->query("
    SELECT c.*, u.full_name AS author, $lessonCnt AS lessons_cnt
    FROM courses c LEFT JOIN users u ON u.id = c.created_by
    WHERE c.status = 'published'
    ORDER BY COALESCE(c.updated_at, c.created_at) DESC, c.id DESC
")->fetchAll();
$mq = $pdo->prepare("
    SELECT c.*, $lessonCnt AS lessons_cnt FROM courses c
    WHERE c.created_by = ? ORDER BY (c.status = 'draft') DESC, COALESCE(c.updated_at, c.created_at) DESC, c.id DESC
");
$mq->execute([uid()]);
$mine = $mq->fetchAll();
$pick = (int)($_GET['pick'] ?? ($mine[0]['id'] ?? 0));

// прогресс по общедоступным курсам
$progress = [];
foreach ($public as $c) {
    $p = course_progress($pdo, (int)$c['id'], uid());
    $progress[(int)$c['id']] = [count(array_filter($p, fn($x) => $x['completed'])), count($p)];
}

page_start('Курсы', ['section' => 'courses']);
?>
<section class="page-head">
    <div>
        <h1>Курсы</h1>
        <div class="muted">Учебные материалы для инструкторов и сотрудников управления.</div>
    </div>
    <a class="btn primary" href="course_wizard.php"><?= icon('plus') ?> Создать курс</a>
</section>

<section>
    <h2>Общедоступные курсы</h2>
    <?php if (!$public): ?>
        <div class="empty">Опубликованных курсов пока нет.</div>
    <?php else: ?>
        <div class="tiles">
            <?php foreach ($public as $c): [$doneL, $allL] = $progress[(int)$c['id']]; ?>
                <a class="tile course-tile" href="course.php?id=<?= (int)$c['id'] ?>">
                    <div class="tile-top">
                        <b class="tile-title"><?= e($c['title']) ?></b>
                        <span class="badge"><?= plural((int)$c['lessons_cnt'], 'урок', 'урока', 'уроков') ?></span>
                    </div>
                    <?php if (trim((string)$c['description']) !== ''): ?>
                        <p class="tile-desc"><?= e(mb_strimwidth((string)$c['description'], 0, 160, '…')) ?></p>
                    <?php endif; ?>
                    <div class="tile-meta"><?= $c['author'] ? 'Автор: ' . e($c['author']) : '' ?></div>
                    <?php if ($allL): ?>
                        <div class="mini-progress" title="Пройдено уроков: <?= $doneL ?> из <?= $allL ?>"><span style="width: <?= round($doneL / $allL * 100) ?>%"></span></div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="mine">
    <h2>Мои курсы <span class="muted small">— видны только вам, пока не опубликованы</span></h2>
    <p class="muted small mt0">Здесь и ваши курсы, и личные копии чужих: откройте общедоступный курс и нажмите «Сохранить себе».</p>
    <?php if (!$mine): ?>
        <div class="empty">
            <p class="mt0">Вы ещё не создали ни одного курса. Создайте свой или откройте общедоступный и нажмите «Сохранить себе» — получится личная копия, которую можно менять под себя.</p>
            <a class="btn primary" href="course_wizard.php"><?= icon('plus') ?> Создать курс</a>
        </div>
    <?php else: ?>
        <div class="my-courses">
            <div class="my-course-list" role="tablist">
                <?php foreach ($mine as $c): ?>
                    <button type="button" class="my-course-item <?= (int)$c['id'] === $pick ? 'active' : '' ?>" data-pick="#cp-<?= (int)$c['id'] ?>" role="tab">
                        <b><?= e($c['title']) ?></b>
                        <span class="muted small">
                            <span class="badge <?= $c['status'] === 'published' ? 'ok' : '' ?>"><?= $c['status'] === 'published' ? 'опубликован' : 'черновик' ?></span>
                            <?= plural((int)$c['lessons_cnt'], 'урок', 'урока', 'уроков') ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="my-course-panels">
                <?php foreach ($mine as $c): $cid = (int)$c['id']; $ls = course_lessons($pdo, $cid); ?>
                    <article class="card my-course-panel" id="cp-<?= $cid ?>" <?= $cid === $pick ? '' : 'hidden' ?>>
                        <div class="tile-top">
                            <h3 class="mt0"><?= e($c['title']) ?></h3>
                            <details class="dropdown right">
                                <summary class="icon-btn" title="Ещё"><?= icon('more') ?></summary>
                                <div class="dropdown-body">
                                    <form method="post">
                                        <input type="hidden" name="course" value="<?= $cid ?>">
                                        <?php if ($c['status'] !== 'published'): ?>
                                            <button name="action" value="publish" class="pick-item"><?= icon('send') ?> Опубликовать</button>
                                        <?php else: ?>
                                            <button name="action" value="unpublish" class="pick-item" data-confirm="Снять курс с публикации? Он станет виден только вам."><?= icon('eye') ?> Снять с публикации</button>
                                        <?php endif; ?>
                                        <button name="action" value="delete" class="pick-item danger" data-confirm="Удалить курс «<?= e($c['title']) ?>» со всеми уроками?"><?= icon('trash') ?> Удалить курс</button>
                                    </form>
                                </div>
                            </details>
                        </div>
                        <?php if (trim((string)$c['description']) !== ''): ?>
                            <p class="course-desc-short"><?= nl2br(e($c['description'])) ?></p>
                        <?php else: ?>
                            <p class="muted small">Описания пока нет.</p>
                        <?php endif; ?>
                        <?php if ($ls): ?>
                            <ol class="lesson-mini">
                                <?php foreach ($ls as $i => $l): ?>
                                    <li><a class="lesson-chip" href="lesson.php?id=<?= (int)$l['id'] ?>"><span class="lesson-num"><?= $i + 1 ?></span><?= e($l['title']) ?></a></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <p class="muted small">Уроков пока нет.</p>
                        <?php endif; ?>
                        <div class="btn-row">
                            <a class="btn primary" href="course.php?id=<?= $cid ?>"><?= icon('eye') ?> Просмотр</a>
                            <a class="btn" href="course_wizard.php?id=<?= $cid ?>"><?= icon('edit') ?> Редактировать</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php page_end();
