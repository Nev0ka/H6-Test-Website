<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Auth;

Auth::start();

if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Sessionen er udløbet. Prøv igen.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::attempt($username, $password)) {
            header('Location: /dashboard.php');
            exit;
        }
        $error = 'Forkert brugernavn eller adgangskode.';
    }
}

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log ind – Serverovervågning</title>
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" action="/login.php" novalidate>
    <div class="login-logo"><i class="fa-solid fa-server" aria-hidden="true"></i></div>
    <h1 class="login-title">Serverovervågning</h1>
    <p class="login-sub">Log ind for at se serverstatus</p>

    <?php if ($error !== null): ?>
      <div class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <label class="login-label" for="username">Brugernavn</label>
    <input class="login-input" type="text" id="username" name="username" autocomplete="username" required autofocus>

    <label class="login-label" for="password">Adgangskode</label>
    <input class="login-input" type="password" id="password" name="password" autocomplete="current-password" required>

    <button class="login-button" type="submit">Log ind</button>
  </form>
</body>
</html>
