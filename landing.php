<?php
// Публичная главная: что за проект, кто ведёт, куда входить. Показывается гостям на index.php.
require_once __DIR__ . '/functions.php';
$i = site_info();
page_start('Аттестация по первой помощи', ['bare' => true]);
?>
<div class="landing">
    <section class="ld-hero">
        <div class="ld-brand"><span class="brand-mark big">✚</span><b>СМСО «Эпсилон»</b></div>
        <h1>Аттестация по первой помощи</h1>
        <p class="ld-lead"><?= $i['site_about'] !== ''
            ? nl2br(e($i['site_about']))
            : 'Сайт для проведения практической аттестации по первой помощи: инструкторы оценивают навыки на станциях по критериям, аттестуемые видят свои результаты.' ?></p>
        <?php if ($i['site_org'] !== ''): ?><p class="ld-org">Проект ведёт: <b><?= e($i['site_org']) ?></b></p><?php endif; ?>
        <div class="ld-actions">
            <a class="btn primary" href="reg.php"><?= icon('user') ?> Я аттестуемый</a>
            <a class="btn" href="login.php"><?= icon('key') ?> Вход для инструкторов</a>
        </div>
    </section>

    <section class="ld-how">
        <h2>Как это устроено</h2>
        <div class="ld-cards">
            <div class="card ld-card"><span class="ld-num">1</span><b>Сотрудник управления создаёт аттестацию</b>
                <p class="muted">Станции, критерии оценки и пороги сдачи. Инструкторы присоединяются по приглашению или коду.</p></div>
            <div class="card ld-card"><span class="ld-num">2</span><b>Инструкторы оценивают навыки</b>
                <p class="muted">На каждой станции инструктор отмечает выполнение критериев. Итог считается автоматически.</p></div>
            <div class="card ld-card"><span class="ld-num">3</span><b>Аттестуемый видит свои результаты</b>
                <p class="muted">Регистрация по QR-коду от сотрудника управления. Учётная запись удаляется вместе с аттестацией.</p></div>
        </div>
    </section>

    <section class="ld-trust card">
        <h2 class="mt0">Безопасность и данные</h2>
        <ul class="ld-list">
            <li>Вход только для участников аттестаций: инструкторов, сотрудников управления и аттестуемых.</li>
            <li>Сайт не принимает платежи, не просит данные банковских карт и не использует вход через чужие сервисы.</li>
            <li>Пароли хранятся только в зашифрованном виде. Данные не передаются третьим лицам и не используются для рекламы.</li>
        </ul>
        <p class="mt0"><a href="privacy.php">Политика конфиденциальности →</a></p>
    </section>

    <?php if (($c = site_contacts_html()) || $i['site_person'] !== ''): ?>
        <section class="ld-contacts">
            <h2>Контакты</h2>
            <?php if ($i['site_person'] !== ''): ?><p class="mt0">Ответственный: <?= e($i['site_person']) ?></p><?php endif; ?>
            <?php if ($c): ?><p class="mt0"><?= $c ?></p><?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?php page_end();
