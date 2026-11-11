<?php
// Раздел «Управление» сотрудника управления: аттестации, которые он ведёт
require_once 'functions.php';
require_role('admin');

/* ============================ ГИ ============================ */
$atts = $pdo->query("
    SELECT a.*,
           (SELECT COUNT(*) FROM stations s WHERE s.attestation_id = a.id) AS stations_cnt,
           (SELECT COUNT(*) FROM attestation_members m WHERE m.attestation_id = a.id) AS members_cnt,
           (SELECT COUNT(DISTINCT r.participant_id) FROM results r JOIN stations s ON s.id = r.station_id
             WHERE s.attestation_id = a.id) AS part_cnt,
           (SELECT COUNT(*) FROM station_judges sj JOIN stations s ON s.id = sj.station_id
             WHERE s.attestation_id = a.id AND sj.approved = 0) AS pending_cnt
    FROM attestations a
    ORDER BY CASE a.status WHEN 'prep' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, a.id DESC
")->fetchAll();

page_start('Управление', ['section' => 'manage']);
manage_tabs('atts');

?>

<section>
    <div class="section-head">
        <div>
            <h2 class="mt0">Все аттестации</h2>
        </div>
    </div>
    <?php if (!$atts): ?>
        <div class="empty">Пока нет ни одной аттестации. Создайте первую — кнопка ниже.</div>
    <?php else: ?>
        <div class="tiles">
            <?php foreach ($atts as $a): ?>
                <a class="tile <?= $a['status'] === 'prep' ? 'tile-draft' : '' ?>" href="<?= $a['status'] === 'prep' ? 'attestation_wizard.php?id=' . (int)$a['id'] : 'attestation.php?id=' . (int)$a['id'] ?>">
                    <div class="tile-top">
                        <b class="tile-title"><?= e($a['title']) ?></b>
                        <span class="badge <?= status_class($a['status']) ?>"><?= e(status_label($a['status'])) ?></span>
                    </div>
                    <div class="tile-meta">
                        Код <code><?= e($a['join_code']) ?></code> ·
                        <?= plural((int)$a['members_cnt'], 'инструктор', 'инструктора', 'инструкторов') ?> ·
                        <?= plural((int)$a['stations_cnt'], 'станция', 'станции', 'станций') ?> ·
                        <?= plural((int)$a['part_cnt'], 'аттестуемый', 'аттестуемых', 'аттестуемых') ?>
                    </div>
                    <?php if ((int)$a['pending_cnt']): ?>
                        <div class="tile-note">⏳ Ждут подтверждения: <?= plural((int)$a['pending_cnt'], 'станция', 'станции', 'станций') ?></div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>


<?php page_end();
