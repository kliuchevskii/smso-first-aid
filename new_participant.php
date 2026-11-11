<?php
// Оставлено для старых ссылок: добавление аттестуемого теперь на странице станции
require_once 'functions.php';
require_login();
redirect('station_instructor.php?id=' . (int)($_GET['station'] ?? 0) . '&new=1');
