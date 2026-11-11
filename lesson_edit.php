<?php
// Уроки теперь создаются и редактируются в мастере урока
require_once 'functions.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$course = (int)($_GET['course'] ?? 0);
redirect($id ? "lesson_wizard.php?lesson=$id" : "lesson_wizard.php?course=$course");
