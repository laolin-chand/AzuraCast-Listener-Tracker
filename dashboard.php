<?php

if (!isset($_GET['view'])) {
    $_GET['view'] = 'dashboard';
}

require __DIR__ . '/monthly.php';
