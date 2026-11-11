<?php
require_once 'functions.php';
// гостям — публичная страница о проекте, а не форма входа
if (empty($_SESSION['user_id'])) { require __DIR__ . '/landing.php'; exit; }
require_login();
enter_instructor();   // сотрудник управления здесь работает как инструктор

/* ====================== Инструктор (и ГИ как инструктор) ====================== */
{
    if (is_post() && isset($_POST['join_code'])) {
        $att = join_attestation($pdo, uid(), (string)$_POST['join_code']);
        if ($att) {
            flash('Вы присоединились к аттестации «' . $att['title'] . '»');
            redirect('attestation_instructor.php?id=' . (int)$att['id']);
        }
        flash('Аттестация с таким кодом не найдена. Проверьте код у сотрудника управления.', 'err');
        redirect('index.php');
    }

    $list = my_attestations($pdo, uid());
    $hello = greet_name($_SESSION['full_name'] ?? '');

    page_start('Мои аттестации');

    ?>
<div class="card nudge" data-push-nudge hidden>
    <span class="feature-ic"><?= icon('bell') ?></span>
    <div class="grow"><b>Включите уведомления на телефон</b><div class="muted small">Приглашения в аттестации будут приходить, даже когда сайт закрыт.</div></div>
    <a class="btn small primary" href="notifications.php">Включить</a>
    <button type="button" class="icon-btn" data-nudge-close aria-label="Скрыть"><?= icon('x') ?></button>
</div>

    <section class="hero">
        <h1>Здравствуйте, <?= e($hello) ?>!</h1>
        <p class="muted">Введите код аттестации или отсканируйте QR-код приглашения — и вы станете судьёй в ней.</p>
        <form method="post" class="join-form">
            <div class="code-field">
                <input name="join_code" id="join-code" class="code-input" placeholder="КОД" maxlength="16" autocomplete="off" required>
                <button type="button" class="scan-btn" data-scan="#scan-dialog" data-scan-target="#join-code" title="Сканировать QR-код" aria-label="Сканировать QR-код"><?= icon('qr') ?></button>
            </div>
            <button class="btn primary">Присоединиться</button>
        </form>
    </section>

    <dialog class="modal scan-modal" id="scan-dialog">
        <div class="modal-head">
            <h2 class="mt0">Сканер QR-кода</h2>
            <button type="button" class="icon-btn" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button>
        </div>
        <div class="scan-view"><video muted playsinline></video><span class="scan-frame"></span></div>
        <p class="muted small center" data-scan-status>Запускаем камеру…</p>
        <div class="modal-actions"><button type="button" class="btn" data-dialog-close>← Назад</button></div>
    </dialog>
    <?php $invites = my_invitations($pdo, uid()); ?>
    <?php if ($invites): ?>
        <section>
            <h2>Приглашения</h2>
            <div class="pick-list">
                <?php foreach ($invites as $a): ?>
                    <div class="pick-row invite-row">
                        <div><b><?= e($a['title']) ?></b><div class="muted small">Вас пригласили инструктором</div></div>
                        <?= invite_buttons($pdo, (int)$a['id'], 'index.php') ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section>
        <h2>Мои аттестации</h2>
        <?php if (!$list): ?>
            <div class="empty">Вы пока не присоединились ни к одной аттестации.</div>
        <?php else: ?>
            <div class="tiles">
                <?php foreach ($list as $a): ?>
                    <a class="tile" href="attestation_instructor.php?id=<?= (int)$a['id'] ?>">
                        <div class="tile-top">
                            <b class="tile-title"><?= e($a['title']) ?></b>
                            <span class="badge <?= status_class($a['status']) ?>"><?= e(status_label($a['status'])) ?></span>
                        </div>
                        <div class="tile-meta">
                            Код <code><?= e($a['join_code']) ?></code> ·
                            <?= (int)$a['my_stations_cnt'] ? plural((int)$a['my_stations_cnt'], 'моя станция', 'мои станции', 'моих станций') : 'станция не выбрана' ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    page_end();
}

