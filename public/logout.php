<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Auth;

Auth::start();
Auth::logout();

header('Location: /login.php');
exit;
