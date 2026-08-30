<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';

Auth::bootSession();

if (!Auth::isSetupComplete()) {
    header('Location: /setup.php');
    exit;
}
if (Auth::isLoggedIn()) {
    header('Location: /library.php');
    exit;
}
header('Location: /login.php');
