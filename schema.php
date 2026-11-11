<?php
/**
 * Структура базы данных и автоматическое обновление.
 * При первом запросе после загрузки новой версии создаёт недостающие
 * таблицы, столбцы и индексы. Отчёт можно посмотреть на install.php.
 */

const SCHEMA_VERSION = 15;

function schema_tables(): array {
    $ci = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'users' => [
            'create' => "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(255) NOT NULL DEFAULT '',
                email VARCHAR(255) NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'instructor',
                must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                temp_password VARCHAR(32) NULL,
                push_off VARCHAR(60) NOT NULL DEFAULT '',
                attestation_id INT NULL,
                participant_id INT NULL,
                doc_officer TINYINT(1) NOT NULL DEFAULT 0,
                doc_tester TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_username (username)
            ) $ci",
            'columns' => [
                'full_name'  => "VARCHAR(255) NOT NULL DEFAULT ''",
                'email'      => "VARCHAR(255) NULL",
                'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 0",
                'temp_password'        => "VARCHAR(32) NULL",
                'push_off'             => "VARCHAR(60) NOT NULL DEFAULT ''",
                'attestation_id'       => "INT NULL",
                'participant_id'       => "INT NULL",
                'role'       => "VARCHAR(20) NOT NULL DEFAULT 'instructor'",
                'created_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
                'doc_officer' => "TINYINT(1) NOT NULL DEFAULT 0",   // ответственный за документооборот
                'doc_tester'  => "TINYINT(1) NOT NULL DEFAULT 0",   // видит «Документы» в тестовом режиме
            ],
            'unique' => [],
        ],
        'attestations' => [
            'create' => "CREATE TABLE IF NOT EXISTS attestations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                join_code VARCHAR(16) NOT NULL,
                created_by INT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                confirmed_at DATETIME NULL,
                one_judge_per_station TINYINT(1) NOT NULL DEFAULT 0,
                allow_own_stations TINYINT(1) NOT NULL DEFAULT 1,
                allow_edit_admin_stations TINYINT(1) NOT NULL DEFAULT 1,
                pass_threshold INT NOT NULL DEFAULT 60,
                wizard_step INT NOT NULL DEFAULT 1,
                participants_by_admin TINYINT(1) NOT NULL DEFAULT 0,
                allow_retake TINYINT(1) NOT NULL DEFAULT 0,
                self_register TINYINT(1) NOT NULL DEFAULT 0,
                show_scores TINYINT(1) NOT NULL DEFAULT 0,
                reg_code VARCHAR(16) NULL,
                verdict_mode VARCHAR(10) NOT NULL DEFAULT 'avg',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_join_code (join_code)
            ) $ci",
            'columns' => [
                'created_by'   => "INT NULL",
                'status'       => "VARCHAR(20) NOT NULL DEFAULT 'draft'",
                'confirmed_at' => "DATETIME NULL",
                'one_judge_per_station'     => "TINYINT(1) NOT NULL DEFAULT 0",
                'allow_own_stations'        => "TINYINT(1) NOT NULL DEFAULT 1",
                'allow_edit_admin_stations' => "TINYINT(1) NOT NULL DEFAULT 1",
                'pass_threshold'            => "INT NOT NULL DEFAULT 60",
                'wizard_step'               => "INT NOT NULL DEFAULT 1",
                'participants_by_admin'     => "TINYINT(1) NOT NULL DEFAULT 0",
                'allow_retake'              => "TINYINT(1) NOT NULL DEFAULT 0",
                'self_register' => "TINYINT(1) NOT NULL DEFAULT 0",
                'show_scores'   => "TINYINT(1) NOT NULL DEFAULT 0",
                'reg_code'      => "VARCHAR(16) NULL",
                'verdict_mode'  => "VARCHAR(10) NOT NULL DEFAULT 'avg'",   // avg — средний % станций, stations — число сданных станций
                'created_at'   => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
            ],
            'unique' => [],
        ],
        'attestation_members' => [
            'create' => "CREATE TABLE IF NOT EXISTS attestation_members (
                attestation_id INT NOT NULL,
                user_id INT NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'active',
                joined_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_member (attestation_id, user_id)
            ) $ci",
            'columns' => [
                'joined_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
                'status'    => "VARCHAR(10) NOT NULL DEFAULT 'active'",
            ],
            'unique' => ['uq_member' => ['attestation_id', 'user_id']],
        ],
        'attestation_participants' => [
            'create' => "CREATE TABLE IF NOT EXISTS attestation_participants (
                attestation_id INT NOT NULL,
                participant_id INT NOT NULL,
                added_by INT NULL,
                added_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                num INT NULL,
                UNIQUE KEY uq_att_part (attestation_id, participant_id)
            ) $ci",
            'columns' => [
                'num' => "INT NULL",   // номер аттестуемого внутри аттестации: 1, 2, 3…
            ],
            'unique' => [],
        ],
        'stations' => [
            'create' => "CREATE TABLE IF NOT EXISTS stations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                attestation_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                created_by INT NULL,
                created_by_admin TINYINT(1) NOT NULL DEFAULT 0,
                approved TINYINT(1) NOT NULL DEFAULT 0,
                pass_threshold INT NOT NULL DEFAULT 60,
                added_late TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_att (attestation_id)
            ) $ci",
            'columns' => [
                'created_by'       => "INT NULL",
                'created_by_admin' => "TINYINT(1) NOT NULL DEFAULT 0",
                'approved'         => "TINYINT(1) NOT NULL DEFAULT 0",
                'pass_threshold'   => "INT NOT NULL DEFAULT 60",
                'added_late'       => "TINYINT(1) NOT NULL DEFAULT 0",   // станция добавлена после начала приёма
                'created_at'       => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
            ],
            'unique' => [],
        ],
        'station_judges' => [
            'create' => "CREATE TABLE IF NOT EXISTS station_judges (
                station_id INT NOT NULL,
                user_id INT NOT NULL,
                approved TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY uq_judge (station_id, user_id)
            ) $ci",
            'columns' => [
                'approved' => "TINYINT(1) NOT NULL DEFAULT 0",
            ],
            'unique' => ['uq_judge' => ['station_id', 'user_id']],
        ],
        'criteria' => [
            'create' => "CREATE TABLE IF NOT EXISTS criteria (
                id INT AUTO_INCREMENT PRIMARY KEY,
                station_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                allowed_scores VARCHAR(64) NOT NULL DEFAULT '0,1',
                KEY idx_station (station_id)
            ) $ci",
            'columns' => [
                'allowed_scores' => "VARCHAR(64) NOT NULL DEFAULT '0,1'",
            ],
            'unique' => [],
        ],
        'participants' => [
            'create' => "CREATE TABLE IF NOT EXISTS participants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                last_name VARCHAR(100) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                middle_name VARCHAR(100) NULL,
                gender VARCHAR(1) NULL,
                birth_date DATE NULL,
                user_id INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_name (last_name, first_name)
            ) $ci",
            'columns' => [
                'created_at'  => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
                'middle_name' => "VARCHAR(100) NULL",
                'gender'      => "VARCHAR(1) NULL",
                'birth_date'  => "DATE NULL",
                'user_id'     => "INT NULL",
            ],
            'unique' => [],
        ],
        'results' => [
            'create' => "CREATE TABLE IF NOT EXISTS results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                participant_id INT NOT NULL,
                station_id INT NOT NULL,
                criteria_id INT NOT NULL,
                instructor_id INT NULL,
                score DECIMAL(4,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_station (station_id),
                KEY idx_participant (participant_id)
            ) $ci",
            'columns' => [
                'station_id'    => "INT NOT NULL DEFAULT 0",
                'instructor_id' => "INT NULL",
                'score'         => "DECIMAL(4,2) NOT NULL DEFAULT 0",
                'created_at'    => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
            ],
            'unique' => [],
        ],
        'courses' => [
            'create' => "CREATE TABLE IF NOT EXISTS courses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                created_by INT NULL,
                updated_by INT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'published',
                wizard_step INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) $ci",
            'columns' => [
                'status'      => "VARCHAR(10) NOT NULL DEFAULT 'published'",
                'wizard_step' => "INT NOT NULL DEFAULT 1",
                'description' => "TEXT NULL",
                'updated_by'  => "INT NULL",
                'updated_at'  => "DATETIME NULL",
            ],
            'unique' => [],
        ],
        'lessons' => [
            'create' => "CREATE TABLE IF NOT EXISTS lessons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                course_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                content MEDIUMTEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                KEY idx_course (course_id)
            ) $ci",
            'columns' => [
                'description' => "TEXT NULL",
                'content'    => "MEDIUMTEXT NULL",
                'sort_order' => "INT NOT NULL DEFAULT 0",
                'updated_by' => "INT NULL",
                'updated_at' => "DATETIME NULL",
            ],
            'unique' => [],
        ],
        'lesson_sections' => [
            'create' => "CREATE TABLE IF NOT EXISTS lesson_sections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lesson_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                KEY idx_lesson (lesson_id)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'lesson_items' => [
            'create' => "CREATE TABLE IF NOT EXISTS lesson_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                section_id INT NOT NULL,
                title VARCHAR(500) NOT NULL,
                content TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                KEY idx_section (section_id)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'lesson_progress' => [
            'create' => "CREATE TABLE IF NOT EXISTS lesson_progress (
                user_id INT NOT NULL,
                lesson_id INT NOT NULL,
                item_id INT NOT NULL,
                done_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_progress (user_id, item_id),
                KEY idx_user_lesson (user_id, lesson_id)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'lesson_done' => [
            'create' => "CREATE TABLE IF NOT EXISTS lesson_done (
                user_id INT NOT NULL,
                lesson_id INT NOT NULL,
                completed_at DATETIME NULL,
                last_section INT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_done (user_id, lesson_id)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'role_codes' => [
            'create' => "CREATE TABLE IF NOT EXISTS role_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'admin',
                note VARCHAR(255) NULL,
                max_uses INT NOT NULL DEFAULT 1,
                used_count INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_code (code)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'role_code_uses' => [
            'create' => "CREATE TABLE IF NOT EXISTS role_code_uses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code_id INT NOT NULL,
                user_id INT NOT NULL,
                used_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_code (code_id)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'notifications' => [
            'create' => "CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                message VARCHAR(500) NOT NULL,
                link VARCHAR(255) NULL,
                kind VARCHAR(20) NULL,
                ref_id INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                seen_at DATETIME NULL,
                read_at DATETIME NULL,
                KEY idx_user_seen (user_id, seen_at)
            ) $ci",
            'columns' => [
                'kind'    => "VARCHAR(20) NULL",
                'ref_id'  => "INT NULL",
                'read_at' => "DATETIME NULL",
            ],
            'unique' => [],
        ],
        'settings' => [
            'create' => "CREATE TABLE IF NOT EXISTS settings (
                k VARCHAR(40) NOT NULL PRIMARY KEY,
                v TEXT NOT NULL
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'push_subscriptions' => [
            'create' => "CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint_hash CHAR(64) NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh VARCHAR(200) NOT NULL,
                auth VARCHAR(100) NOT NULL,
                ua VARCHAR(200) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                last_ok DATETIME NULL,
                UNIQUE KEY uq_endpoint (endpoint_hash),
                KEY idx_user (user_id)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'password_resets' => [
            'create' => "CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NULL,
                via VARCHAR(10) NOT NULL DEFAULT 'admin',
                status VARCHAR(10) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                done_at DATETIME NULL,
                KEY idx_user (user_id)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        // Документооборот: шаблоны .docx (файл хранится в базе) и заявки на документы
        'doc_templates' => [
            'create' => "CREATE TABLE IF NOT EXISTS doc_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description VARCHAR(1000) NOT NULL DEFAULT '',
                filename VARCHAR(255) NOT NULL DEFAULT '',
                content MEDIUMBLOB NOT NULL,
                fields TEXT NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
        'doc_requests' => [
            'create' => "CREATE TABLE IF NOT EXISTS doc_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NOT NULL,
                template_title VARCHAR(255) NOT NULL DEFAULT '',
                user_id INT NULL,
                user_name VARCHAR(255) NOT NULL DEFAULT '',
                data MEDIUMTEXT NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'new',
                comment VARCHAR(1000) NOT NULL DEFAULT '',
                handled_by INT NULL,
                handled_at DATETIME NULL,
                created_at DATETIME NULL,
                KEY idx_status (status),
                KEY idx_user (user_id)
            ) $ci",
            'columns' => [],
            'unique' => [],
        ],
    ];
}

/** Выполнить обновление структуры. Возвращает список строк-отчёта. */
function migrate_schema(PDO $pdo): array {
    $log = [];
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();

    foreach (schema_tables() as $table => $def) {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
        $exists->execute([$db, $table]);
        if (!(int)$exists->fetchColumn()) {
            $pdo->exec($def['create']);
            $log[] = ['ok', "Создана таблица $table"];
            continue;
        }

        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
        $cols->execute([$db, $table]);
        $have = array_map('strtolower', $cols->fetchAll(PDO::FETCH_COLUMN));

        foreach ($def['columns'] as $col => $sql) {
            if (in_array(strtolower($col), $have, true)) continue;
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $sql");
            $log[] = ['ok', "Таблица $table: добавлен столбец $col"];

            // Перенос старых подтверждений станций в подтверждения судей
            if ($table === 'station_judges' && $col === 'approved') {
                $pdo->exec("UPDATE station_judges sj JOIN stations s ON s.id = sj.station_id SET sj.approved = 1 WHERE s.approved = 1");
                $log[] = ['ok', 'Подтверждения станций перенесены на судей'];
            }
        }

        // Уникальные ключи (защищают от дублей при повторных нажатиях)
        foreach ($def['unique'] as $name => $fields) {
            $idx = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND COLUMN_NAME = ?");
            $idx->execute([$db, $table, $fields[1]]);
            if ((int)$idx->fetchColumn()) continue;
            try {
                $list = implode(', ', $fields);
                $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$name` ($list)");
                $log[] = ['ok', "Таблица $table: добавлен уникальный ключ ($list)"];
            } catch (PDOException $e) {
                $log[] = ['warn', "Таблица $table: не удалось добавить ключ (возможно, в таблице есть дубли) — " . $e->getMessage()];
            }
        }
    }

    // Обязательный столбец criteria_id: в старых версиях мог называться иначе
    $cols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'results'");
    $cols->execute([$db]);
    $have = array_map('strtolower', $cols->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('criteria_id', $have, true)) {
        if (in_array('criterion_id', $have, true)) {
            $pdo->exec("ALTER TABLE results CHANGE criterion_id criteria_id INT NOT NULL");
            $log[] = ['ok', 'results: столбец criterion_id переименован в criteria_id'];
        } else {
            $pdo->exec("ALTER TABLE results ADD COLUMN criteria_id INT NOT NULL DEFAULT 0");
            $log[] = ['warn', 'results: добавлен столбец criteria_id (раньше его не было)'];
        }
    }

    // users.role раньше мог быть ENUM('admin','instructor') — нужна ещё роль sysadmin
    $rt = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
    $rt->execute([$db]);
    $type = strtolower((string)$rt->fetchColumn());
    if (str_starts_with($type, 'enum')) {
        $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'instructor'");
        $log[] = ['ok', 'users.role: ENUM заменён на VARCHAR(20)'];
    }

    // Самостоятельно зарегистрированные однофамильцы — разные люди: уникальность по ФИ в participants не нужна
    $ux = $pdo->prepare("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'participants' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'");
    $ux->execute([$db]);
    foreach ($ux->fetchAll(PDO::FETCH_COLUMN) as $ix) {
        try {
            $pdo->exec("ALTER TABLE participants DROP INDEX `$ix`");
            $log[] = ['ok', "participants: снят уникальный ключ $ix (однофамильцы-аттестуемые теперь разные люди)"];
        } catch (PDOException $e) {
            $log[] = ['warn', "participants: не удалось снять ключ $ix — " . $e->getMessage()];
        }
    }

    // Номера аттестуемых внутри каждой аттестации: тем, у кого номера ещё нет, — по порядку записи
    $fixed = renumber_participants($pdo);
    if ($fixed) $log[] = ['ok', "attestation_participants: присвоены номера внутри аттестаций ($fixed)"];

    if (!$log) $log[] = ['ok', 'Структура базы в порядке, изменений не требуется'];
    return $log;
}

function schema_marker_file(): string {
    return __DIR__ . '/.schema_version';
}

/** Запускается из functions.php: обновляет схему один раз после выкладки новой версии. */
function ensure_schema(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    if (($_SESSION['schema_ok'] ?? 0) === SCHEMA_VERSION) return;
    $f = schema_marker_file();
    if (is_file($f) && (int)@file_get_contents($f) === SCHEMA_VERSION) {
        $_SESSION['schema_ok'] = SCHEMA_VERSION;
        return;
    }
    try {
        migrate_schema($pdo);
        @file_put_contents($f, (string)SCHEMA_VERSION);
        $_SESSION['schema_ok'] = SCHEMA_VERSION;
    } catch (Throwable $e) {
        // не блокируем сайт; подробности покажет install.php
        error_log('ensure_schema: ' . $e->getMessage());
    }
}

/** Проставить номера аттестуемым без номера: внутри каждой аттестации — следом за уже выданными */
function renumber_participants(PDO $pdo): int {
    $rows = $pdo->query("SELECT attestation_id, participant_id FROM attestation_participants
                         WHERE num IS NULL ORDER BY attestation_id, added_at, participant_id")->fetchAll();
    if (!$rows) return 0;
    $max = [];
    foreach ($pdo->query("SELECT attestation_id, MAX(num) AS m FROM attestation_participants GROUP BY attestation_id") as $r) {
        $max[(int)$r['attestation_id']] = (int)$r['m'];
    }
    $up = $pdo->prepare("UPDATE attestation_participants SET num = ? WHERE attestation_id = ? AND participant_id = ?");
    foreach ($rows as $r) {
        $a = (int)$r['attestation_id'];
        $max[$a] = ($max[$a] ?? 0) + 1;
        $up->execute([$max[$a], $a, $r['participant_id']]);
    }
    return count($rows);
}
