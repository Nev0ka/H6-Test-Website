<?php

declare(strict_types=1);

// CLI only — creates or updates a user. Run: php scripts/create_admin.php <username> <display_name> <role: admin|operator>
// Prompts for a password interactively so it never ends up in shell history.

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

if (php_sapi_name() !== 'cli') {
    exit('Kun til CLI-brug.' . PHP_EOL);
}

[$script, $username, $displayName, $role] = array_pad($argv, 4, null);

if ($username === null || $displayName === null) {
    fwrite(STDERR, "Brug: php scripts/create_admin.php <username> <display_name> [admin|operator]\n");
    exit(1);
}
$role = in_array($role, ['admin', 'operator'], true) ? $role : 'admin';

fwrite(STDOUT, 'Adgangskode: ');
system('stty -echo');
$password = trim((string) fgets(STDIN));
system('stty echo');
fwrite(STDOUT, PHP_EOL);

if (strlen($password) < 10) {
    fwrite(STDERR, "Adgangskoden skal være mindst 10 tegn.\n");
    exit(1);
}

$initials = strtoupper(substr($displayName, 0, 1) . (strpos($displayName, ' ') !== false ? substr(strrchr($displayName, ' '), 1, 1) : ''));

$pdo = Database::connection();
$stmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash, display_name, initials, role)
     VALUES (:u, :p, :d, :i, :r)
     ON DUPLICATE KEY UPDATE password_hash = :p2, display_name = :d2, initials = :i2, role = :r2'
);
$hash = password_hash($password, PASSWORD_ARGON2ID);
$stmt->execute([
    'u' => $username, 'p' => $hash, 'd' => $displayName, 'i' => $initials, 'r' => $role,
    'p2' => $hash, 'd2' => $displayName, 'i2' => $initials, 'r2' => $role,
]);

echo "Bruger '{$username}' oprettet/opdateret som {$role}.\n";
