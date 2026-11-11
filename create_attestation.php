<?php
// Создание аттестации теперь идёт через мастер
require_once 'functions.php';
require_role('admin');
redirect('attestation_wizard.php');
