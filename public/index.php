<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Auth;

Auth::start();
header('Location: ' . (Auth::check() ? '/dashboard.php' : '/login.php'));
exit;
